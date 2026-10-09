<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\People\Models\ExaminerAssignment;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Timetabling\Enums\TimetableStatus;
use App\Domain\Timetabling\Models\LessonRequirement;
use App\Domain\Timetabling\Models\TeacherSubject;
use App\Domain\Timetabling\Models\TeachingAssignment;
use App\Domain\Timetabling\Models\Timetable;
use App\Domain\Timetabling\Models\TimetableLesson;
use Database\Seeders\DemoSchoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Timetables end to end against the demo school, which seeds a school day,
 * Grade 5 Blue's 26 lessons a week, its subject teachers (class teacher:
 * English and Social Studies; examiner: the rest) and a published timetable.
 * Generation runs on the sync queue here, so POST /timetables finishes in the
 * request.
 */
class TimetablesApiTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    private User $teacher;

    private User $examiner;

    private SchoolClass $blue;

    private AcademicYear $year;

    private Timetable $published;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->school = (new DemoSchoolSeeder)->run('Gatimu Primary', 'gatimu', 'gatimu.educa.test');

        $this->actingForSchool($this->school);
        $this->admin = User::query()->where('username', 'admin')->firstOrFail();
        $this->teacher = User::query()->where('username', 'teacher')->firstOrFail();
        $this->examiner = User::query()->where('username', 'examiner')->firstOrFail();
        $this->blue = SchoolClass::query()->firstOrFail();
        $this->year = AcademicYear::query()->where('is_current', true)->firstOrFail();
        $this->published = Timetable::query()->where('status', TimetableStatus::PUBLISHED)->firstOrFail();
        $this->forgetTenant();
    }

    private function generate(array $body = []): Timetable
    {
        $id = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/timetables', $body)
            ->assertStatus(202)
            ->json('data.id');

        return $this->tenancy()->asSchool($this->school, fn () => Timetable::query()->findOrFail($id));
    }

    /** @param  list<string>  $codes  subject codes */
    private function tick(User $user, array $codes): void
    {
        $this->tenancy()->asSchool($this->school, function () use ($user, $codes): void {
            foreach (LevelSubject::query()->whereHas('subject', fn ($q) => $q->whereIn('code', $codes))->get() as $ls) {
                TeacherSubject::factory()->create(['school_id' => $this->school->id, 'user_id' => $user->id, 'level_subject_id' => $ls->id, 'academic_year_id' => $this->year->id]);
            }
        });
    }

    /** @return list<TimetableLesson> */
    private function lessonsOf(Timetable $timetable): array
    {
        return $this->tenancy()->asSchool($this->school, fn () => TimetableLesson::query()->where('timetable_id', $timetable->id)->get()->all());
    }

    // -------------------------------------------------------------- readiness

    public function test_the_demo_school_is_ready_and_its_free_periods_are_a_warning_only(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/timetables/readiness')
            ->assertOk()
            ->assertJsonPath('data.ready', true)
            ->assertJsonPath('data.summary.lessons', 26)
            ->assertJsonPath('data.issues.0.code', 'free_periods')
            ->assertJsonPath('data.issues.0.level', 'warning');
    }

    public function test_a_subject_without_a_teacher_blocks_generation_and_says_which(): void
    {
        $this->tenancy()->asSchool($this->school, fn () => TeachingAssignment::query()->where('user_id', $this->teacher->id)->delete());

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/timetables/readiness')
            ->assertJsonPath('data.ready', false)
            ->assertJsonFragment(['code' => 'missing_teacher', 'message' => 'Grade 5 Blue has no teacher for English, Social Studies.', 'fix' => 'teachers']);

        $this->postJson('/api/timetables')
            ->assertStatus(422)
            ->assertJsonPath('readiness.ready', false);
    }

    public function test_more_lessons_than_periods_blocks_generation(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/timetable/requirements', [
                'academic_year_id' => $this->year->id,
                'grade_id' => $this->blue->grade_id,
                'requirements' => $this->tenancy()->asSchool($this->school, fn () => LevelSubject::query()->get()->map(fn ($ls) => ['level_subject_id' => $ls->id, 'lessons_per_week' => 9, 'max_per_day' => 2])->all()),
            ])->assertOk();

        $this->getJson('/api/timetables/readiness')
            ->assertJsonPath('data.ready', false)
            ->assertJsonFragment(['code' => 'too_many_lessons', 'message' => 'Grade 5 Blue has 45 lessons a week but only 40 lesson periods.']);

        // Everything taught by the examiner: 45 lessons for one person in 40 periods.
        $this->tenancy()->asSchool($this->school, fn () => TeachingAssignment::query()->update(['user_id' => $this->examiner->id]));

        $this->getJson('/api/timetables/readiness')
            ->assertJsonFragment(['code' => 'teacher_overloaded', 'message' => 'Jane Akinyi has 45 lessons a week but their classes only have 40 periods between them.']);
    }

    public function test_with_no_pins_a_subject_nobody_ticked_is_missing_a_teacher(): void
    {
        $this->tenancy()->asSchool($this->school, fn () => TeachingAssignment::query()->delete());
        $this->tick($this->teacher, ['ENG', 'SST', 'KIS']);
        $this->tick($this->examiner, ['KIS']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/timetables/readiness')
            ->assertJsonPath('data.ready', false)
            ->assertJsonFragment(['code' => 'missing_teacher', 'message' => 'Grade 5 Blue has no teacher for Mathematics, Science and Technology.', 'fix' => 'teachers']);
    }

    public function test_a_subject_whose_teachers_have_too_few_periods_blocks_generation(): void
    {
        // Five classes of Grade 5 with 9 Mathematics lessons each: 45 lessons,
        // and the one teacher who ticked Mathematics has 40 periods.
        $this->tenancy()->asSchool($this->school, function (): void {
            TeachingAssignment::query()->delete();
            foreach (['Red', 'Green', 'Yellow', 'White'] as $colour) {
                SchoolClass::factory()->create(['school_id' => $this->school->id, 'grade_id' => $this->blue->grade_id, 'stream_id' => null, 'name' => "Grade 5 {$colour}"]);
            }
            LessonRequirement::query()->whereHas('levelSubject.subject', fn ($q) => $q->where('code', 'MAT'))->update(['lessons_per_week' => 9, 'double_lessons' => 0]);
        });
        $this->tick($this->teacher, ['ENG', 'SST', 'MAT']);
        $this->tick($this->examiner, ['KIS', 'SCI']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/timetables/readiness')
            ->assertJsonPath('data.ready', false)
            ->assertJsonFragment(['code' => 'subject_capacity', 'message' => 'Mathematics (Upper Primary) needs 45 lessons a week but the 1 teacher who teaches it has 40 periods between them.']);
    }

    // ------------------------------------------------------------- generation

    public function test_without_pins_the_generator_gives_each_class_a_teacher_who_ticked_the_subject(): void
    {
        $this->tenancy()->asSchool($this->school, fn () => TeachingAssignment::query()->delete());
        $this->tick($this->teacher, ['ENG', 'SST', 'MAT']);
        $this->tick($this->examiner, ['MAT', 'KIS', 'SCI', 'ENG']);

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/timetables/readiness')->assertJsonPath('data.ready', true);

        $timetable = $this->generate();

        $this->assertSame(TimetableStatus::DRAFT, $timetable->status, json_encode($timetable->diagnostics));
        $this->assertGreaterThanOrEqual(1, $timetable->stats['allocations']);
        $lessons = collect($this->lessonsOf($timetable));
        $this->assertCount(26, $lessons);

        $ticked = $this->tenancy()->asSchool($this->school, fn () => TeacherSubject::query()->get()->map(fn ($t) => "{$t->user_id}:{$t->level_subject_id}")->all());
        foreach ($lessons as $lesson) {
            $this->assertContains("{$lesson->user_id}:{$lesson->level_subject_id}", $ticked, 'A lesson went to a teacher who did not tick its subject');
        }

        // One teacher per class and subject, not a different one per lesson.
        $this->assertSame(5, $lessons->groupBy('level_subject_id')->filter(fn ($l) => $l->pluck('user_id')->unique()->count() === 1)->count());
    }

    public function test_a_pin_wins_over_the_teachers_who_ticked_the_subject(): void
    {
        $english = $this->tenancy()->asSchool($this->school, function () {
            $english = LevelSubject::query()->whereHas('subject', fn ($q) => $q->where('code', 'ENG'))->firstOrFail();
            TeachingAssignment::query()->where('level_subject_id', '!=', $english->id)->delete();
            TeachingAssignment::query()->where('level_subject_id', $english->id)->update(['user_id' => $this->examiner->id]);

            return $english;
        });
        $this->tick($this->teacher, ['ENG', 'SST', 'MAT', 'KIS', 'SCI']);

        $timetable = $this->generate();

        $this->assertSame(TimetableStatus::DRAFT, $timetable->status, json_encode($timetable->diagnostics));
        $lessons = collect($this->lessonsOf($timetable));
        $this->assertSame([$this->examiner->id], $lessons->where('level_subject_id', $english->id)->pluck('user_id')->unique()->values()->all());
        $this->assertSame([$this->teacher->id], $lessons->where('level_subject_id', '!=', $english->id)->pluck('user_id')->unique()->values()->all());
    }

    public function test_generating_builds_a_clash_free_draft_with_every_lesson(): void
    {
        $timetable = $this->generate(['name' => 'Term 1 draft']);

        $this->assertSame(TimetableStatus::DRAFT, $timetable->status);
        $lessons = collect($this->lessonsOf($timetable));
        $this->assertCount(26, $lessons);
        $this->assertCount(26, $lessons->unique(fn ($l) => "{$l->day}:{$l->period_sequence}"), 'Class double-booked');
        $this->assertSame(10, $lessons->where('user_id', $this->teacher->id)->count());
        $this->assertSame(2, $lessons->whereNotNull('double_group')->groupBy('double_group')->count());

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/timetables/{$timetable->id}/classes/{$this->blue->id}")
            ->assertOk()
            ->assertJsonCount(26, 'data.lessons')
            ->assertJsonPath('data.schedule.days', [1, 2, 3, 4, 5])
            ->assertJsonPath('data.timetable.status', 'draft');

        $this->getJson("/api/timetables/{$timetable->id}/teachers/{$this->examiner->id}")
            ->assertOk()
            ->assertJsonCount(16, 'data.lessons');
    }

    public function test_only_one_generation_runs_per_year_at_a_time(): void
    {
        $this->tenancy()->asSchool($this->school, fn () => Timetable::factory()->create([
            'school_id' => $this->school->id, 'academic_year_id' => $this->year->id, 'status' => TimetableStatus::GENERATING,
        ]));

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/timetables')->assertStatus(409);
    }

    public function test_a_lost_run_does_not_block_the_school_forever(): void
    {
        $stuck = $this->tenancy()->asSchool($this->school, fn () => Timetable::factory()->create([
            'school_id' => $this->school->id, 'academic_year_id' => $this->year->id, 'status' => TimetableStatus::GENERATING,
        ]));
        Timetable::query()->acrossTenants()->whereKey($stuck->id)->update(['updated_at' => now()->subHour()]);

        $this->generate();

        $this->assertSame(TimetableStatus::FAILED, Timetable::query()->acrossTenants()->find($stuck->id)->status);
    }

    public function test_regenerating_keeps_locked_lessons_where_they_are(): void
    {
        $draft = $this->generate();
        $lesson = $this->lessonsOf($draft)[0];

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/timetables/{$draft->id}/lessons/{$lesson->id}", ['is_locked' => true])
            ->assertOk()
            ->assertJsonPath('data.is_locked', true);

        $next = $this->generate(['keep_locked_from' => $draft->id]);
        $kept = collect($this->lessonsOf($next))->first(fn ($l) => $l->day === $lesson->day && $l->period_sequence === $lesson->period_sequence);

        $this->assertNotNull($kept);
        $this->assertSame($lesson->level_subject_id, $kept->level_subject_id);
        $this->assertTrue($kept->is_locked);
    }

    // ---------------------------------------------------------------- editing

    public function test_a_lesson_moves_to_a_free_period_and_swaps_with_an_occupied_one(): void
    {
        $draft = $this->generate();
        $lessons = collect($this->lessonsOf($draft))->whereNull('double_group')->values();
        $lesson = $lessons[0];
        $options = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/timetables/{$draft->id}/lessons/{$lesson->id}/options")
            ->assertOk()
            ->json('data');

        $free = collect($options)->first(fn ($o) => $o['occupant_id'] === null && $o['allowed']);
        $this->patchJson("/api/timetables/{$draft->id}/lessons/{$lesson->id}", ['day' => $free['day'], 'period_sequence' => $free['period_sequence']])
            ->assertOk()
            ->assertJsonPath('data.day', $free['day'])
            ->assertJsonPath('data.starts_at', $free['starts_at']);

        $swap = collect($this->actingAs($this->admin, 'sanctum')->getJson("/api/timetables/{$draft->id}/lessons/{$lesson->id}/options")->json('data'))
            ->first(fn ($o) => $o['occupant_id'] !== null && $o['allowed']);
        $this->assertNotNull($swap, 'Expected at least one allowed swap');
        $this->patchJson("/api/timetables/{$draft->id}/lessons/{$lesson->id}", ['day' => $swap['day'], 'period_sequence' => $swap['period_sequence']])->assertOk();

        $occupant = $this->tenancy()->asSchool($this->school, fn () => TimetableLesson::query()->find($swap['occupant_id']));
        $this->assertSame([$free['day'], $free['period_sequence']], [$occupant->day, $occupant->period_sequence]);
    }

    public function test_a_move_that_double_books_a_teacher_is_refused(): void
    {
        $draft = $this->generate();
        // A second class taught by the same examiner, with a lesson at a time
        // the examiner already teaches Grade 5 Blue.
        $busy = collect($this->lessonsOf($draft))->firstWhere('user_id', $this->examiner->id);
        $red = $this->tenancy()->asSchool($this->school, fn () => SchoolClass::factory()->create([
            'school_id' => $this->school->id, 'grade_id' => $this->blue->grade_id, 'stream_id' => null, 'name' => 'Grade 5 Red',
        ]));
        $other = $this->tenancy()->asSchool($this->school, fn () => TimetableLesson::factory()->create([
            'school_id' => $this->school->id, 'timetable_id' => $draft->id, 'class_id' => $red->id,
            'level_subject_id' => $busy->level_subject_id, 'user_id' => $this->examiner->id,
            'day' => $busy->day, 'period_sequence' => $busy->period_sequence === 1 ? 2 : 1,
            'starts_at' => '06:00', 'ends_at' => '06:30',
        ]));

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/timetables/{$draft->id}/lessons/{$other->id}", ['day' => $busy->day, 'period_sequence' => $busy->period_sequence])
            ->assertStatus(409)
            ->assertJsonPath('message', 'The teacher already has a lesson then.');
    }

    public function test_a_published_timetable_cannot_be_edited_or_deleted(): void
    {
        $lesson = $this->lessonsOf($this->published)[0];

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/timetables/{$this->published->id}/lessons/{$lesson->id}", ['is_locked' => true])
            ->assertStatus(409);

        $this->deleteJson("/api/timetables/{$this->published->id}")->assertStatus(409);
    }

    public function test_a_lesson_from_another_timetable_is_not_found(): void
    {
        $draft = $this->generate();
        $foreign = $this->lessonsOf($this->published)[0];

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/timetables/{$draft->id}/lessons/{$foreign->id}", ['is_locked' => true])
            ->assertNotFound();
    }

    // -------------------------------------------------------------- lifecycle

    public function test_publishing_archives_the_previous_timetable(): void
    {
        $draft = $this->generate();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/timetables/{$draft->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $this->assertSame(TimetableStatus::ARCHIVED, Timetable::query()->acrossTenants()->find($this->published->id)->status);
        $this->deleteJson("/api/timetables/{$this->published->id}")->assertOk();
    }

    public function test_duplicating_a_published_timetable_gives_an_editable_draft(): void
    {
        $id = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/timetables/{$this->published->id}/duplicate")
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.lessons_count', 26)
            ->json('data.id');

        $this->assertNotSame($this->published->id, $id);
    }

    // ----------------------------------------------------------------- access

    public function test_staff_cannot_manage_timetables(): void
    {
        foreach ([$this->teacher, $this->examiner] as $user) {
            $this->app['auth']->forgetGuards();
            $this->actingAs($user, 'sanctum')->postJson('/api/timetables')->assertForbidden();
            $this->getJson('/api/timetables')->assertForbidden();
            $this->getJson('/api/timetable/bell-schedules')->assertForbidden();
        }
    }

    public function test_another_school_cannot_see_this_schools_timetables(): void
    {
        (new DemoSchoolSeeder)->run('Riverside Academy', 'riverside', 'riverside.educa.test');
        $otherAdmin = User::query()->where('username', 'admin.riverside')->firstOrFail();

        $this->actingAs($otherAdmin, 'sanctum')->getJson("/api/timetables/{$this->published->id}")->assertNotFound();
        $this->getJson("/api/timetables/{$this->published->id}/teachers/{$this->teacher->id}")->assertNotFound();
        $this->assertCount(1, $this->getJson('/api/timetables')->json('data'));
    }

    // ----------------------------------------------------------------- portal

    public function test_a_teacher_sees_only_their_own_lessons_from_the_published_timetable(): void
    {
        $this->generate(); // a newer draft: must not reach staff

        $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/me/timetable')
            ->assertOk()
            ->assertJsonPath('data.timetable.id', $this->published->id)
            ->assertJsonCount(10, 'data.lessons')
            ->assertJsonPath('data.teacher.id', $this->teacher->id);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->examiner, 'sanctum')
            ->getJson('/api/me/timetable')
            ->assertOk()
            ->assertJsonCount(16, 'data.lessons');
    }

    public function test_a_class_teacher_sees_their_class_week_but_not_another_class(): void
    {
        $red = $this->tenancy()->asSchool($this->school, fn () => SchoolClass::factory()->create([
            'school_id' => $this->school->id, 'grade_id' => $this->blue->grade_id, 'stream_id' => null, 'name' => 'Grade 5 Red',
        ]));

        $this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/my/classes/{$this->blue->id}/timetable")
            ->assertOk()
            ->assertJsonCount(26, 'data.lessons');

        $this->getJson("/api/my/classes/{$red->id}/timetable")->assertForbidden();
    }

    public function test_with_nothing_published_the_portal_says_so(): void
    {
        Timetable::query()->acrossTenants()->whereKey($this->published->id)->update(['status' => TimetableStatus::ARCHIVED->value]);

        $this->actingAs($this->teacher, 'sanctum')->getJson('/api/me/timetable')->assertOk()->assertJsonPath('data', null);
    }

    // ------------------------------------------------------------------ set-up

    public function test_bell_schedules_refuse_overlapping_periods_and_defaults_fill_missing_levels(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/timetable/bell-schedules', [
                'level_id' => null, 'name' => 'Default', 'days' => [1, 2, 3, 4, 5],
                'periods' => [
                    ['kind' => 'lesson', 'starts_at' => '08:00', 'ends_at' => '08:40'],
                    ['kind' => 'lesson', 'starts_at' => '08:30', 'ends_at' => '09:10'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('periods.1.starts_at');

        $this->postJson('/api/timetable/bell-schedules/defaults')
            ->assertOk()
            ->assertJsonPath('data.created', 2)
            ->assertJsonCount(3, 'data.schedules');
    }

    /**
     * The admin app sends the year in the query string, where every value is
     * a string. Under strict_types an unconverted "1" reached forGrade(int)
     * and the Lessons per week screen failed with a TypeError.
     */
    public function test_requirements_accept_ids_sent_as_strings(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/timetable/requirements?academic_year_id={$this->year->id}&grade_id={$this->blue->grade_id}")
            ->assertOk()
            ->assertJsonCount(5, 'data.subjects')
            ->assertJsonPath('data.academic_year_id', $this->year->id);

        $this->putJson('/api/timetable/requirements', [
            'academic_year_id' => (string) $this->year->id,
            'grade_id' => (string) $this->blue->grade_id,
            'requirements' => [],
        ])->assertOk()->assertJsonPath('data.lessons_per_week', 0);
    }

    public function test_requirements_list_every_subject_and_refuse_more_doubles_than_lessons_allow(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/timetable/requirements?grade_id={$this->blue->grade_id}")
            ->assertOk()
            ->assertJsonCount(5, 'data.subjects')
            ->assertJsonPath('data.lessons_per_week', 26)
            ->assertJsonPath('data.periods_per_week', 40);

        $mathematics = $this->tenancy()->asSchool($this->school, fn () => LevelSubject::query()->whereHas('subject', fn ($q) => $q->where('code', 'MAT'))->firstOrFail());

        $this->putJson('/api/timetable/requirements', [
            'academic_year_id' => $this->year->id,
            'grade_id' => $this->blue->grade_id,
            'requirements' => [['level_subject_id' => $mathematics->id, 'lessons_per_week' => 3, 'max_per_day' => 2, 'double_lessons' => 2]],
        ])->assertStatus(422)->assertJsonValidationErrors('requirements.0.double_lessons');
    }

    public function test_teaching_assignments_are_set_cleared_and_filled_from_examiners(): void
    {
        $english = $this->tenancy()->asSchool($this->school, fn () => LevelSubject::query()->whereHas('subject', fn ($q) => $q->where('code', 'ENG'))->firstOrFail());

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/classes/{$this->blue->id}/teaching-assignments", [
                'academic_year_id' => $this->year->id,
                'subjects' => [['level_subject_id' => $english->id, 'user_id' => null]],
            ])
            ->assertOk()
            ->assertJsonCount(4, 'data.assignments');

        // The examiner marks every subject of Grade 5 Blue, so English comes back to them.
        $this->postJson('/api/teaching-assignments/from-examiners', ['academic_year_id' => $this->year->id])
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.skipped', 4);

        $this->assertSame($this->examiner->id, $this->tenancy()->asSchool($this->school, fn () => TeachingAssignment::query()->where('level_subject_id', $english->id)->value('user_id')));
        $this->assertSame(5, $this->tenancy()->asSchool($this->school, fn () => ExaminerAssignment::query()->count()));
    }

    public function test_only_active_staff_of_the_school_can_be_assigned_to_teach(): void
    {
        $english = $this->tenancy()->asSchool($this->school, fn () => LevelSubject::query()->whereHas('subject', fn ($q) => $q->where('code', 'ENG'))->firstOrFail());
        $outsider = User::factory()->create(['school_id' => School::factory()->create()->id]);
        $inactive = User::factory()->inactive()->create(['school_id' => $this->school->id]);

        foreach ([$outsider, $inactive] as $user) {
            $this->actingAs($this->admin, 'sanctum')
                ->putJson("/api/classes/{$this->blue->id}/teaching-assignments", [
                    'academic_year_id' => $this->year->id,
                    'subjects' => [['level_subject_id' => $english->id, 'user_id' => $user->id]],
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('subjects.0.user_id');
        }
    }
}
