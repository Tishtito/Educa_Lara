<?php

declare(strict_types=1);

namespace App\Domain\People\Services;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\People\Models\Student;
use App\Domain\People\Models\StudentEnrolment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * End of year: every pupil moves up a grade, and the top grade leaves. Legacy
 * did this class by class ("Move Class" / "Graduate Class") by overwriting
 * students.class, which re-labelled the pupils' past results with the new class.
 * Here each pupil gets a new enrolment for the new year; the old one is closed
 * and kept.
 */
class PromotionService
{
    /** @return list<array<string, mixed>> One row per class with pupils, and where they would go. */
    public function preview(AcademicYear $from, AcademicYear $to): array
    {
        $classes = SchoolClass::query()->with(['grade', 'stream'])->get();
        $counts = StudentEnrolment::query()
            ->where('academic_year_id', $from->id)
            ->where('status', 'active')
            ->selectRaw('class_id, COUNT(*) as pupils')
            ->groupBy('class_id')
            ->pluck('pupils', 'class_id');
        $alreadyThere = StudentEnrolment::query()
            ->where('academic_year_id', $to->id)
            ->whereIn('student_id', StudentEnrolment::query()->where('academic_year_id', $from->id)->select('student_id'))
            ->count();

        return $classes
            ->filter(fn (SchoolClass $c) => ($counts[$c->id] ?? 0) > 0)
            ->sortBy(fn (SchoolClass $c) => [$c->grade?->ordinal, $c->name])
            ->map(fn (SchoolClass $c) => [
                'class_id' => $c->id,
                'class_name' => $c->name,
                'grade' => $c->grade?->name,
                'pupils' => (int) $counts[$c->id],
                'suggestion' => $this->suggest($c, $classes),
                'already_enrolled_in_target_year' => $alreadyThere,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array{class_id: int, action: string, target_class_id?: ?int}>  $mappings  action: promote|graduate|skip
     * @param  list<int>  $excludedStudentIds  Pupils repeating the year or leaving: left untouched.
     * @return array{promoted: int, graduated: int, already_enrolled: int, excluded: int, skipped: int, dry_run: bool}
     */
    public function promote(AcademicYear $from, AcademicYear $to, array $mappings, array $excludedStudentIds, bool $dryRun): array
    {
        if ($from->is($to)) {
            throw ValidationException::withMessages(['to_year_id' => 'Choose a different year to promote into.']);
        }

        $targets = SchoolClass::query()->whereIn('id', collect($mappings)->pluck('target_class_id')->filter())->where('is_active', true)->pluck('id');
        foreach ($mappings as $i => $mapping) {
            if ($mapping['action'] === 'promote' && ! $targets->contains((int) ($mapping['target_class_id'] ?? 0))) {
                throw ValidationException::withMessages(["mappings.{$i}.target_class_id" => 'Choose an active class to promote into.']);
            }
        }

        $tally = ['promoted' => 0, 'graduated' => 0, 'already_enrolled' => 0, 'excluded' => 0, 'skipped' => 0, 'dry_run' => $dryRun];
        $excluded = collect($excludedStudentIds)->map(fn ($id) => (int) $id)->flip();

        $work = function () use ($from, $to, $mappings, $excluded, &$tally): void {
            $enrolledNextYear = StudentEnrolment::query()->where('academic_year_id', $to->id)->pluck('student_id')->flip();

            foreach ($mappings as $mapping) {
                $enrolments = StudentEnrolment::query()
                    ->where('academic_year_id', $from->id)
                    ->where('class_id', $mapping['class_id'])
                    ->where('status', 'active')
                    ->get();

                foreach ($enrolments as $enrolment) {
                    if (isset($excluded[$enrolment->student_id])) {
                        $tally['excluded']++;

                        continue;
                    }
                    if ($mapping['action'] === 'skip') {
                        $tally['skipped']++;

                        continue;
                    }
                    if (isset($enrolledNextYear[$enrolment->student_id])) {
                        // Already placed (by hand, or an earlier run): never overwrite it.
                        $tally['already_enrolled']++;

                        continue;
                    }

                    $enrolment->forceFill(['status' => 'promoted', 'ended_on' => $from->ends_on ?? now()->toDateString()])->save();

                    if ($mapping['action'] === 'graduate') {
                        Student::query()->whereKey($enrolment->student_id)->update(['status' => 'graduated']);
                        $tally['graduated']++;

                        continue;
                    }

                    StudentEnrolment::create([
                        'school_id' => $enrolment->school_id,
                        'student_id' => $enrolment->student_id,
                        'academic_year_id' => $to->id,
                        'class_id' => $mapping['target_class_id'],
                        'status' => 'active',
                        'started_on' => $to->starts_on ?? now()->toDateString(),
                    ]);
                    $tally['promoted']++;
                }
            }
        };

        if ($dryRun) {
            // Same code path as the real run, rolled back, so the preview counts cannot drift from what happens.
            DB::beginTransaction();
            try {
                $work();
            } finally {
                DB::rollBack();
            }
        } else {
            DB::transaction($work);
        }

        return $tally;
    }

    /**
     * The same stream one grade up; the only class one grade up; graduation
     * when the school teaches nothing higher. A gap (no class at the next grade
     * but classes above it) is left for the administrator to decide.
     *
     * @param  Collection<int, SchoolClass>  $classes
     * @return array{action: string, target_class_id: ?int, target_class_name: ?string}
     */
    private function suggest(SchoolClass $class, Collection $classes): array
    {
        $nextOrdinal = ($class->grade?->ordinal ?? 0) + 1;
        $next = $classes->filter(fn (SchoolClass $c) => $c->is_active && $c->grade?->ordinal === $nextOrdinal);

        $target = $next->firstWhere('stream_id', $class->stream_id)
            ?? $next->first(fn (SchoolClass $c) => $class->stream && $c->stream && strcasecmp($c->stream->name, $class->stream->name) === 0)
            ?? ($next->count() === 1 ? $next->first() : null);

        if ($target !== null) {
            return ['action' => 'promote', 'target_class_id' => $target->id, 'target_class_name' => $target->name];
        }

        $teachesHigher = $classes->contains(fn (SchoolClass $c) => $c->is_active && ($c->grade?->ordinal ?? 0) > ($class->grade?->ordinal ?? 0));

        return $next->isEmpty() && ! $teachesHigher
            ? ['action' => 'graduate', 'target_class_id' => null, 'target_class_name' => null]
            : ['action' => 'promote', 'target_class_id' => null, 'target_class_name' => null];
    }
}
