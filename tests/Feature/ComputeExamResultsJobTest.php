<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Examination\Enums\ExamStatus;
use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Models\ExamStudentResult;
use App\Domain\Tenancy\Models\School;
use App\Jobs\ComputeExamResults;
use Database\Seeders\DemoSchoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComputeExamResultsJobTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private Exam $exam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->school = (new DemoSchoolSeeder)->run('Gatimu Primary', 'gatimu', 'gatimu.educa.test');

        $this->exam = $this->tenancy()->asSchool($this->school, function () {
            $exam = Exam::query()->firstOrFail();
            $exam->update(['status' => ExamStatus::MARKING]);
            ExamStudentResult::query()->where('exam_id', $exam->id)->forceDelete();

            return $exam;
        });
    }

    /** Round-trips the job through serialisation, as a real queue does. */
    private function runOnWorker(ComputeExamResults $job): void
    {
        $this->forgetTenant();   // a worker has no tenant
        $restored = unserialize(serialize($job));
        app()->call([$restored, 'handle']);
    }

    public function test_it_computes_on_a_worker_with_no_tenant(): void
    {
        // If the job held the Exam model, SerializesModels would re-fetch it here
        // through TenantScope with no tenant resolved, and it would fail closed.
        $this->runOnWorker(new ComputeExamResults($this->exam->id));

        $count = $this->tenancy()->asSchool($this->school, fn () => ExamStudentResult::query()->where('exam_id', $this->exam->id)->count());
        $this->assertSame(8, $count);
    }

    public function test_it_skips_an_exam_that_is_no_longer_computable(): void
    {
        $this->tenancy()->asSchool($this->school, fn () => $this->exam->update(['status' => ExamStatus::PUBLISHED]));

        $this->runOnWorker(new ComputeExamResults($this->exam->id));

        $count = $this->tenancy()->asSchool($this->school, fn () => ExamStudentResult::query()->where('exam_id', $this->exam->id)->count());
        $this->assertSame(0, $count);
    }

    public function test_it_skips_a_deleted_exam_without_failing(): void
    {
        $this->runOnWorker(new ComputeExamResults(999999));

        $this->assertTrue(true);
    }

    public function test_jobs_for_the_same_exam_share_a_uniqueness_key(): void
    {
        $this->assertSame(
            (new ComputeExamResults($this->exam->id))->uniqueId(),
            (new ComputeExamResults($this->exam->id))->uniqueId(),
        );
        $this->assertNotSame(
            (new ComputeExamResults(1))->uniqueId(),
            (new ComputeExamResults(2))->uniqueId(),
        );
    }
}
