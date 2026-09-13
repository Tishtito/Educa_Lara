<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Examination\Models\Exam;
use App\Domain\People\Models\User;
use Database\Seeders\DemoSchoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Replaces legacy $_SESSION['exam_id']. */
class SelectedExamTest extends TestCase
{
    use RefreshDatabase;

    private User $examiner;

    private Exam $exam;

    private Exam $theirExam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $school = (new DemoSchoolSeeder)->run('Gatimu Primary', 'gatimu', 'gatimu.educa.test');
        $other = (new DemoSchoolSeeder)->run('Riverside Academy', 'riverside', 'riverside.educa.test');

        [$this->examiner, $this->exam] = $this->tenancy()->asSchool($school, fn () => [
            User::query()->where('school_id', $school->id)->where('username', 'examiner')->firstOrFail(),
            Exam::query()->firstOrFail(),
        ]);
        $this->theirExam = $this->tenancy()->asSchool($other, fn () => Exam::query()->firstOrFail());
    }

    public function test_nothing_is_selected_at_first(): void
    {
        $this->actingAs($this->examiner, 'sanctum')->getJson('/api/me/selected-exam')->assertOk()->assertJsonPath('data', null);
    }

    public function test_a_selection_is_remembered(): void
    {
        $this->actingAs($this->examiner, 'sanctum')
            ->putJson('/api/me/selected-exam', ['exam_id' => $this->exam->id])
            ->assertOk()->assertJsonPath('data.id', $this->exam->id);

        $this->app['auth']->forgetGuards();

        $this->actingAs($this->examiner->fresh(), 'sanctum')
            ->getJson('/api/me/selected-exam')->assertOk()->assertJsonPath('data.id', $this->exam->id);
    }

    public function test_another_schools_exam_cannot_be_selected(): void
    {
        $this->actingAs($this->examiner, 'sanctum')
            ->putJson('/api/me/selected-exam', ['exam_id' => $this->theirExam->id])
            ->assertStatus(422)->assertJsonValidationErrors('exam_id');
    }

    public function test_a_selection_that_no_longer_exists_reads_as_none(): void
    {
        $this->actingAs($this->examiner, 'sanctum')->putJson('/api/me/selected-exam', ['exam_id' => $this->exam->id])->assertOk();
        $this->tenancy()->withoutScope(fn () => Exam::query()->whereKey($this->exam->id)->delete());

        $this->app['auth']->forgetGuards();

        $this->actingAs($this->examiner->fresh(), 'sanctum')->getJson('/api/me/selected-exam')->assertOk()->assertJsonPath('data', null);
    }
}
