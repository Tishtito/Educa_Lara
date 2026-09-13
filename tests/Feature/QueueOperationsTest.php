<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Examination\Enums\ExamStatus;
use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Models\ExamSubjectScore;
use App\Domain\Examination\Services\ExamComputationService;
use App\Domain\People\Models\StudentEnrolment;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Jobs\ComputeExamResults;
use App\Notifications\QueueJobFailed;
use App\Notifications\QueueRecovered;
use App\Notifications\QueueUnhealthy;
use App\Operations\QueueHealth;
use Database\Seeders\DemoSchoolSeeder;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/** A job that always fails, carrying an exam id like ComputeExamResults does. */
class AlwaysFailsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $examId) {}

    public function handle(): void
    {
        throw new RuntimeException('boom');
    }
}

class QueueOperationsTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private School $other;

    private Exam $exam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->school = (new DemoSchoolSeeder)->run('Gatimu Primary', 'gatimu', 'gatimu.educa.test');
        $this->other = (new DemoSchoolSeeder)->run('Riverside Academy', 'riverside', 'riverside.educa.test');

        $this->exam = $this->tenancy()->asSchool($this->school, fn () => Exam::query()->firstOrFail());
        $this->setStatus($this->exam, ExamStatus::MARKING);

        config(['educa.ops.alert_email' => 'ops@educa.test']);
    }

    // ---------------------------------------------------------------- helpers

    private function setStatus(Exam $exam, ExamStatus $status): void
    {
        $this->tenancy()->withoutScope(fn () => Exam::query()->whereKey($exam->id)->update(['status' => $status->value]));
    }

    private function stamp(Exam $exam, ?string $changed, ?string $computed): void
    {
        $this->tenancy()->withoutScope(fn () => Exam::query()->whereKey($exam->id)
            ->update(['marks_changed_at' => $changed, 'results_computed_at' => $computed]));
    }

    private function fresh(Exam $exam): Exam
    {
        return $this->tenancy()->withoutScope(fn () => Exam::query()->findOrFail($exam->id));
    }

    private function saveOneMark(): TestResponse
    {
        [$admin, $enrolment, $score] = $this->tenancy()->asSchool($this->school, function () {
            $score = ExamSubjectScore::query()->entered()->where('exam_id', $this->exam->id)->where('is_absent', false)->firstOrFail();

            return [
                User::query()->where('school_id', $this->school->id)->where('username', 'admin')->firstOrFail(),
                StudentEnrolment::query()->findOrFail($score->enrolment_id),
                $score,
            ];
        });

        return $this->actingAs($admin, 'sanctum')->putJson("/api/exams/{$this->exam->id}/marksheet", [
            'class_id' => $score->class_id,
            'level_subject_id' => $score->level_subject_id,
            'marks' => [[
                'enrolment_id' => $enrolment->id,
                'subject_paper_id' => $score->subject_paper_id,
                'raw_score' => (float) $score->raw_score === 1.0 ? 2.0 : 1.0,
                'is_absent' => false,
            ]],
        ]);
    }

    /** Heartbeats for every configured worker, plus the scheduler. */
    private function everythingReportsIn(): void
    {
        $health = app(QueueHealth::class);

        foreach (config('educa.queue.workers') as $worker) {
            $health->recordWorkerHeartbeat($worker['connection'], $worker['queues']);
        }

        $health->recordSchedulerHeartbeat();
    }

    // ------------------------------------------------------------ job routing

    public function test_result_computation_goes_to_the_results_queue(): void
    {
        Queue::fake();

        $this->saveOneMark()->assertOk();

        Queue::assertPushedOn('results', ComputeExamResults::class);
    }

    public function test_the_job_timeout_stays_below_its_connections_retry_after(): void
    {
        // Otherwise the database driver hands a still-running job to a second worker.
        $this->assertLessThan(
            (int) config('queue.connections.database.retry_after'),
            (new ComputeExamResults(1))->timeout,
        );
    }

    public function test_alert_notifications_are_never_queued(): void
    {
        // An alert about a broken queue must not depend on the queue.
        foreach ([QueueUnhealthy::class, QueueRecovered::class, QueueJobFailed::class] as $class) {
            $this->assertNotContains(ShouldQueue::class, class_implements($class), $class);
        }
    }

    // ------------------------------------------------------------- staleness

    public function test_saving_marks_makes_results_stale_until_they_are_computed(): void
    {
        Queue::fake();   // hold the job, so the stale window is observable

        $this->saveOneMark()->assertOk();
        $this->assertTrue($this->fresh($this->exam)->resultsAreStale());

        app(ExamComputationService::class)->compute($this->fresh($this->exam));
        $this->assertFalse($this->fresh($this->exam)->resultsAreStale());
    }

    public function test_a_mark_saved_during_a_computation_still_counts_as_stale(): void
    {
        // Computation started at .100, a mark landed at .300 in the same second.
        $this->stamp($this->exam, '2026-09-13 10:00:05.300000', '2026-09-13 10:00:05.100000');

        $this->assertTrue($this->fresh($this->exam)->resultsAreStale());
    }

    public function test_changing_max_marks_under_existing_marks_makes_results_stale(): void
    {
        Queue::fake();
        [$admin, $paperId, $classId] = $this->tenancy()->asSchool($this->school, function () {
            $score = ExamSubjectScore::query()->entered()->where('exam_id', $this->exam->id)->firstOrFail();

            return [User::query()->where('school_id', $this->school->id)->where('username', 'admin')->firstOrFail(), $score->subject_paper_id, $score->class_id];
        });
        app(ExamComputationService::class)->compute($this->fresh($this->exam));

        $this->actingAs($admin, 'sanctum')->putJson("/api/exams/{$this->exam->id}/max-marks", [
            'subject_paper_id' => $paperId, 'class_id' => $classId, 'max_marks' => 150, 'apply_to_existing' => true,
        ])->assertOk();

        $this->assertTrue($this->fresh($this->exam)->resultsAreStale());
    }

    public function test_the_api_reports_whether_results_are_up_to_date(): void
    {
        $this->saveOneMark()->assertOk();   // sync queue: recomputed immediately

        $admin = $this->tenancy()->asSchool($this->school, fn () => User::query()->where('school_id', $this->school->id)->where('username', 'admin')->firstOrFail());
        $class = $this->tenancy()->asSchool($this->school, fn () => SchoolClass::query()->firstOrFail());

        $this->actingAs($admin, 'sanctum')->getJson("/api/exams/{$this->exam->id}/marklist?class_id={$class->id}")
            ->assertOk()->assertJsonPath('data.exam.results_up_to_date', true);

        $this->stamp($this->exam, now()->addMinute()->format('Y-m-d H:i:s.u'), now()->format('Y-m-d H:i:s.u'));

        $this->actingAs($admin, 'sanctum')->getJson("/api/exams/{$this->exam->id}/marking")
            ->assertOk()->assertJsonPath('data.exam.results_up_to_date', false);
    }

    // ------------------------------------------------------------ safety net

    public function test_recompute_stale_dispatches_missed_exams_in_every_school(): void
    {
        Queue::fake();
        $theirs = $this->tenancy()->asSchool($this->other, fn () => Exam::query()->firstOrFail());
        $this->setStatus($theirs, ExamStatus::LOCKED);

        $old = now()->subMinutes(10);
        $this->stamp($this->exam, $old->format('Y-m-d H:i:s.u'), $old->copy()->subMinute()->format('Y-m-d H:i:s.u'));
        $this->stamp($theirs, $old->format('Y-m-d H:i:s.u'), null);   // never computed

        $this->artisan('exams:recompute-stale')->assertSuccessful();

        Queue::assertPushed(ComputeExamResults::class, 2);
        Queue::assertPushed(ComputeExamResults::class, fn ($job) => $job->examId === $this->exam->id);
        Queue::assertPushed(ComputeExamResults::class, fn ($job) => $job->examId === $theirs->id);
    }

    public function test_recompute_stale_recovers_an_exam_whose_job_was_lost_while_holding_its_unique_lock(): void
    {
        // Found by running the checked-in Supervisor config: a recomputation that
        // is lost before a worker starts it leaves its uniqueness lock held, and
        // every later dispatch for that exam is silently dropped as a duplicate —
        // including this safety net's own.
        Queue::fake();
        $lost = new ComputeExamResults($this->exam->id);
        $this->assertTrue((new UniqueLock(Cache::store()))->acquire($lost), 'Could not simulate the held lock.');

        ComputeExamResults::dispatch($this->exam->id);
        Queue::assertNothingPushed();   // demonstrates the trap: a plain dispatch is swallowed

        $old = now()->subMinutes(10);
        $this->stamp($this->exam, $old->format('Y-m-d H:i:s.u'), $old->copy()->subMinute()->format('Y-m-d H:i:s.u'));

        $this->artisan('exams:recompute-stale')->assertSuccessful();

        Queue::assertPushed(ComputeExamResults::class, fn ($job) => $job->examId === $this->exam->id);
    }

    public function test_a_lost_jobs_uniqueness_lock_cannot_outlive_five_minutes(): void
    {
        // Bounds the damage even when the scheduler (and so the safety net) is down.
        $this->assertLessThanOrEqual(300, (new ComputeExamResults(1))->uniqueFor());
        $this->assertGreaterThan(0, (new ComputeExamResults(1))->uniqueFor(), '0 means the cache default: 24 hours.');
    }

    public function test_recompute_stale_leaves_recent_changes_to_the_job_already_dispatched(): void
    {
        Queue::fake();
        $this->stamp($this->exam, now()->subSeconds(30)->format('Y-m-d H:i:s.u'), now()->subMinute()->format('Y-m-d H:i:s.u'));

        $this->artisan('exams:recompute-stale')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_recompute_stale_ignores_exams_that_are_up_to_date(): void
    {
        Queue::fake();
        $old = now()->subMinutes(10);
        $this->stamp($this->exam, $old->format('Y-m-d H:i:s.u'), $old->copy()->addSecond()->format('Y-m-d H:i:s.u'));

        $this->artisan('exams:recompute-stale')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_recompute_stale_never_touches_a_published_exam(): void
    {
        Queue::fake();
        $this->setStatus($this->exam, ExamStatus::PUBLISHED);
        $this->stamp($this->exam, now()->subMinutes(10)->format('Y-m-d H:i:s.u'), null);

        $this->artisan('exams:recompute-stale')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    // ------------------------------------------------------------- heartbeat

    public function test_a_polling_worker_records_a_heartbeat_at_most_every_30_seconds(): void
    {
        // A queue name unique to this test: throttling state lives in the process.
        $queue = 'heartbeat-test-'.uniqid();
        $key = QueueHealth::heartbeatKey('database', $queue);

        event(new Looping('database', $queue));
        $this->assertNotNull(Cache::get($key));

        Cache::forget($key);
        event(new Looping('database', $queue));
        $this->assertNull(Cache::get($key), 'Rewritten on every poll instead of throttled.');
    }

    // ---------------------------------------------------------------- health

    private function up(): TestResponse
    {
        config(['educa.queue.check_on_up' => true, 'app.debug' => false]);

        return $this->get('/up');
    }

    public function test_up_is_healthy_when_workers_and_scheduler_report_in(): void
    {
        $this->everythingReportsIn();

        $this->up()->assertOk();
    }

    public function test_up_fails_when_a_worker_stops_reporting(): void
    {
        $this->everythingReportsIn();
        $this->travel(3)->minutes();
        app(QueueHealth::class)->recordSchedulerHeartbeat();   // scheduler fine, workers silent

        $this->up()->assertStatus(500);
    }

    public function test_up_fails_when_a_job_waits_too_long(): void
    {
        Queue::connection('database')->pushRaw(json_encode(['displayName' => 'Stuck', 'job' => 'x', 'data' => []]), 'results');

        $this->travel(6)->minutes();
        $this->everythingReportsIn();   // workers alive, but not draining

        $this->up()->assertStatus(500);
    }

    public function test_up_fails_when_the_scheduler_stops(): void
    {
        $this->everythingReportsIn();
        $this->travel(11)->minutes();
        foreach (config('educa.queue.workers') as $w) {
            app(QueueHealth::class)->recordWorkerHeartbeat($w['connection'], $w['queues']);
        }

        $this->up()->assertStatus(500);
    }

    public function test_up_ignores_the_queue_when_the_check_is_disabled(): void
    {
        config(['educa.queue.check_on_up' => false, 'app.debug' => false]);

        $this->get('/up')->assertOk();
    }

    // ---------------------------------------------------------------- alerts

    public function test_an_incident_is_mailed_once_and_its_recovery_once(): void
    {
        Notification::fake();

        $this->artisan('ops:check-queue')->assertFailed();    // no worker has ever reported
        $this->artisan('ops:check-queue')->assertFailed();    // still down: no second mail

        Notification::assertSentOnDemandTimes(QueueUnhealthy::class, 1);

        $this->everythingReportsIn();
        $this->artisan('ops:check-queue')->assertSuccessful();
        $this->artisan('ops:check-queue')->assertSuccessful();

        Notification::assertSentOnDemandTimes(QueueRecovered::class, 1);
        Notification::assertSentOnDemand(QueueUnhealthy::class, fn ($n, $channels, AnonymousNotifiable $to) => $to->routes['mail'] === 'ops@educa.test');
    }

    public function test_no_mail_is_attempted_without_an_alert_address(): void
    {
        Notification::fake();
        config(['educa.ops.alert_email' => null]);

        $this->artisan('ops:check-queue')->assertFailed();

        Notification::assertNothingSent();
    }

    public function test_a_failed_job_is_mailed_once_per_record_per_hour(): void
    {
        Notification::fake();

        foreach ([7, 7, 8] as $examId) {
            try {
                AlwaysFailsJob::dispatch($examId);
            } catch (RuntimeException) {
                // the sync queue rethrows after firing JobFailed
            }
        }

        Notification::assertSentOnDemandTimes(QueueJobFailed::class, 2);   // exam 7 once, exam 8 once
        Notification::assertSentOnDemand(QueueJobFailed::class, fn (QueueJobFailed $n) => ($n->context['Exam'] ?? null) === '7');
    }

    // ------------------------------------------------------- platform report

    public function test_a_superadmin_can_read_the_queue_report(): void
    {
        $superadmin = User::factory()->platformAdmin()->create();
        $this->everythingReportsIn();

        $this->actingAs($superadmin, 'sanctum')->getJson('/api/platform/queue-health')
            ->assertOk()
            ->assertJsonPath('data.healthy', true)
            ->assertJsonStructure(['data' => ['problems', 'workers' => [['name', 'heartbeat_age_seconds', 'queues' => [['queue', 'pending', 'oldest_pending_age_seconds']]]], 'scheduler', 'failed_jobs']]);
    }

    public function test_a_school_admin_cannot_read_the_queue_report(): void
    {
        $admin = $this->tenancy()->asSchool($this->school, fn () => User::query()->where('school_id', $this->school->id)->where('username', 'admin')->firstOrFail());

        $this->actingAs($admin, 'sanctum')->getJson('/api/platform/queue-health')->assertStatus(403);
    }
}
