<?php

declare(strict_types=1);

namespace App\Domain\Timetabling\Services;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Term;
use App\Domain\People\Models\User;
use App\Domain\Timetabling\Enums\TimetableStatus;
use App\Domain\Timetabling\Models\BellSchedule;
use App\Domain\Timetabling\Models\Timetable;
use App\Domain\Timetabling\Models\TimetableLesson;
use Illuminate\Support\Collection;

/** The shapes the admin app and the staff portal read. */
class TimetableViews
{
    public function __construct(private readonly BellScheduleService $schedules) {}

    /**
     * The timetable staff should be following now: the current year's published
     * one for the current term, else the year's term-less one.
     */
    public function current(): ?Timetable
    {
        $year = AcademicYear::query()->where('is_current', true)->first();

        if ($year === null) {
            return null;
        }

        $termId = Term::query()->where('academic_year_id', $year->id)->where('is_current', true)->value('id');

        return Timetable::query()
            ->where('academic_year_id', $year->id)
            ->where('status', TimetableStatus::PUBLISHED)
            ->where(fn ($q) => $q->whereNull('term_id')->when($termId, fn ($q) => $q->orWhere('term_id', $termId)))
            ->orderByRaw('term_id IS NULL')
            ->orderByDesc('published_at')
            ->first();
    }

    public function summary(Timetable $timetable): array
    {
        $timetable->loadMissing(['academicYear', 'term', 'generatedBy']);

        return [
            'id' => $timetable->id,
            'name' => $timetable->name,
            'status' => $timetable->status->value,
            'academic_year' => ['id' => $timetable->academic_year_id, 'name' => $timetable->academicYear?->name],
            'term' => $timetable->term === null ? null : ['id' => $timetable->term->id, 'name' => $timetable->term->name],
            'seed' => $timetable->seed,
            'source_timetable_id' => $timetable->source_timetable_id,
            'stats' => $timetable->stats,
            'diagnostics' => $timetable->diagnostics,
            'generated_by' => $timetable->generatedBy === null ? null : ['id' => $timetable->generatedBy->id, 'name' => $timetable->generatedBy->name],
            'lessons_count' => $timetable->lessons_count ?? $timetable->lessons()->count(),
            'created_at' => $timetable->created_at?->toIso8601String(),
            'started_at' => $timetable->started_at?->toIso8601String(),
            'generated_at' => $timetable->generated_at?->toIso8601String(),
            'published_at' => $timetable->published_at?->toIso8601String(),
        ];
    }

    /** The summary, plus which classes and teachers it covers (for the admin app's pickers). */
    public function detail(Timetable $timetable): array
    {
        $classes = TimetableLesson::query()->where('timetable_id', $timetable->id)->distinct()->pluck('class_id');
        $teachers = TimetableLesson::query()->where('timetable_id', $timetable->id)
            ->selectRaw('user_id, COUNT(*) as lessons')->groupBy('user_id')->pluck('lessons', 'user_id');

        return $this->summary($timetable) + [
            'classes' => SchoolClass::query()->whereIn('id', $classes)->with('grade')->get()
                ->sortBy(fn ($c) => [$c->grade?->ordinal ?? 0, $c->name])
                ->map(fn (SchoolClass $c) => ['id' => $c->id, 'name' => $c->name, 'grade_id' => $c->grade_id])->values(),
            'teachers' => User::query()->where('school_id', $timetable->school_id)->whereIn('id', $teachers->keys())->orderBy('name')->get()
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'lessons' => (int) $teachers[$u->id]])->values(),
        ];
    }

    public function forClass(Timetable $timetable, SchoolClass $class): array
    {
        $class->loadMissing('grade');
        $lessons = $this->lessons($timetable)->where('class_id', $class->id)->get();

        return [
            'timetable' => $this->summary($timetable),
            'class' => ['id' => $class->id, 'name' => $class->name],
            'schedule' => $this->schedule($this->schedules->forLevel($class->grade?->level_id), $lessons),
            'lessons' => $lessons->map(fn ($l) => $this->lesson($l))->values(),
        ];
    }

    public function forTeacher(Timetable $timetable, User $teacher): array
    {
        $lessons = $this->lessons($timetable)->where('user_id', $teacher->id)->get();

        return [
            'timetable' => $this->summary($timetable),
            'teacher' => ['id' => $teacher->id, 'name' => $teacher->name],
            'days' => $this->days($lessons),
            'lessons' => $lessons->map(fn ($l) => $this->lesson($l))->values(),
        ];
    }

    private function lessons(Timetable $timetable)
    {
        return TimetableLesson::query()
            ->where('timetable_id', $timetable->id)
            ->with(['schoolClass', 'levelSubject.subject', 'teacher'])
            ->orderBy('day')
            ->orderBy('starts_at');
    }

    public function lesson(TimetableLesson $lesson): array
    {
        $subject = $lesson->levelSubject?->subject;

        return [
            'id' => $lesson->id,
            'class_id' => $lesson->class_id,
            'class_name' => $lesson->schoolClass?->name,
            'level_subject_id' => $lesson->level_subject_id,
            'subject_name' => $subject?->name,
            'subject_code' => $subject?->short_name ?: $subject?->code,
            'user_id' => $lesson->user_id,
            'teacher_name' => $lesson->teacher?->name,
            'day' => $lesson->day,
            'period_sequence' => $lesson->period_sequence,
            'starts_at' => substr((string) $lesson->starts_at, 0, 5),
            'ends_at' => substr((string) $lesson->ends_at, 0, 5),
            'is_locked' => $lesson->is_locked,
            'double_group' => $lesson->double_group,
        ];
    }

    /**
     * The day's rows for a class grid. Breaks come from the current bell
     * schedule; a lesson period that no longer exists there (the schedule was
     * edited after generation) is added back from the lessons' own times.
     *
     * @param  Collection<int, TimetableLesson>  $lessons
     */
    private function schedule(?BellSchedule $schedule, Collection $lessons): array
    {
        $periods = collect($schedule?->periods ?? [])->map(fn ($p) => [
            'sequence' => $p->sequence,
            'kind' => $p->kind,
            'label' => $p->label,
            'starts_at' => substr((string) $p->starts_at, 0, 5),
            'ends_at' => substr((string) $p->ends_at, 0, 5),
        ])->keyBy('sequence');

        foreach ($lessons as $lesson) {
            if (! $periods->has($lesson->period_sequence)) {
                $periods->put($lesson->period_sequence, [
                    'sequence' => $lesson->period_sequence,
                    'kind' => 'lesson',
                    'label' => null,
                    'starts_at' => substr((string) $lesson->starts_at, 0, 5),
                    'ends_at' => substr((string) $lesson->ends_at, 0, 5),
                ]);
            }
        }

        $days = $schedule?->dayNumbers() ?? [];

        return [
            'days' => $days === [] ? $this->days($lessons) : $days,
            'periods' => $periods->sortKeys()->values(),
        ];
    }

    /** @return list<int> */
    private function days(Collection $lessons): array
    {
        $days = array_values(array_unique(array_merge([1, 2, 3, 4, 5], $lessons->pluck('day')->map(fn ($d) => (int) $d)->all())));
        sort($days);

        return $days;
    }
}
