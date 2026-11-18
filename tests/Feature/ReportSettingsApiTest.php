<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Academics\Models\Term;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Models\SchoolSetting;
use Database\Seeders\DemoSchoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private School $other;

    private User $admin;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->school = (new DemoSchoolSeeder)->run('Gatimu Primary School', 'gatimu', 'gatimu.educa.test');
        $this->other = (new DemoSchoolSeeder)->run('Riverside Academy', 'riverside', 'riverside.educa.test');

        $users = User::query()->where('school_id', $this->school->id)->get()->keyBy('username');
        [$this->admin, $this->teacher] = [$users['admin'], $users['teacher']];
    }

    private function term(School $school, int $number): Term
    {
        return $this->tenancy()->asSchool($school, fn () => Term::query()->where('term_number', $number)->firstOrFail());
    }

    public function test_an_admin_lists_and_updates_term_dates_and_feeding_fee(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/terms')->assertOk()->assertJsonCount(3, 'data');

        $term = $this->term($this->school, 2);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/terms/{$term->id}", ['starts_on' => '2026-05-04', 'ends_on' => '2026-08-14', 'feeding_fee' => 3750])
            ->assertOk()
            ->assertJsonPath('data.starts_on', '2026-05-04')
            ->assertJsonPath('data.feeding_fee', 3750);
    }

    public function test_a_term_cannot_close_before_it_opens(): void
    {
        $term = $this->term($this->school, 2);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/terms/{$term->id}", ['starts_on' => '2026-08-14', 'ends_on' => '2026-05-04'])
            ->assertStatus(422)->assertJsonValidationErrors('ends_on');
    }

    public function test_another_schools_term_is_not_found(): void
    {
        $theirs = $this->term($this->other, 2);

        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/terms/{$theirs->id}", ['feeding_fee' => 1])->assertStatus(404);
    }

    public function test_a_class_teacher_cannot_change_terms_or_settings(): void
    {
        $this->actingAs($this->teacher, 'sanctum')->patchJson("/api/terms/{$this->term($this->school, 2)->id}", ['feeding_fee' => 1])->assertStatus(403);
        $this->actingAs($this->teacher, 'sanctum')->putJson('/api/school/report-card-settings', ['head_teacher_name' => 'x', 'footer' => null])->assertStatus(403);
    }

    public function test_an_admin_sets_and_clears_report_card_settings(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/school/report-card-settings', ['head_teacher_name' => 'Dr. Jane Mwangi', 'footer' => 'Please sign and return.'])
            ->assertOk()
            ->assertJsonPath('data.head_teacher_name', 'Dr. Jane Mwangi')
            ->assertJsonPath('data.footer', 'Please sign and return.');

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/school/report-card-settings', ['head_teacher_name' => null, 'footer' => null])
            ->assertOk()
            ->assertJsonPath('data.head_teacher_name', null)
            ->assertJsonPath('data.footer', null);
    }

    public function test_settings_never_touch_another_school(): void
    {
        // SchoolSetting is not tenant-scoped, so the controller filters by school itself.
        SchoolSetting::create(['school_id' => $this->other->id, 'key' => 'report_card.footer', 'value' => 'Riverside footer']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/school/report-card-settings')
            ->assertJsonPath('data.footer', null)
            ->assertJsonPath('data.head_teacher_name', 'Mary Wanjiku');

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/school/report-card-settings', ['head_teacher_name' => null, 'footer' => null])
            ->assertOk();

        $this->assertSame('Riverside footer', SchoolSetting::query()->where('school_id', $this->other->id)->where('key', 'report_card.footer')->value('value'));
        $this->assertSame('Mary Wanjiku', SchoolSetting::query()->where('school_id', $this->other->id)->where('key', 'report_card.head_teacher_name')->value('value'));
    }

    // -------------------------------------------------------------- template

    public function test_the_template_defaults_to_classic_and_lists_every_choice(): void
    {
        $data = $this->actingAs($this->admin, 'sanctum')->getJson('/api/school/report-card-settings')->assertOk()->json('data');

        $this->assertSame('classic', $data['template']);
        $this->assertSame(['classic', 'modern', 'formal'], array_column($data['templates'], 'value'));

        foreach ($data['templates'] as $option) {
            $this->assertNotSame('', $option['label']);
            $this->assertNotSame('', $option['description']);
        }
    }

    public function test_an_admin_chooses_a_template(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/school/report-card-settings', ['head_teacher_name' => 'Mary Wanjiku', 'footer' => null, 'template' => 'modern'])
            ->assertOk()
            ->assertJsonPath('data.template', 'modern');

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/school/report-card-settings')->assertJsonPath('data.template', 'modern');
    }

    public function test_saving_without_a_template_keeps_the_chosen_one(): void
    {
        // A client written before templates existed sends only the two text fields.
        SchoolSetting::create(['school_id' => $this->school->id, 'key' => 'report_card.template', 'value' => 'formal']);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/school/report-card-settings', ['head_teacher_name' => null, 'footer' => null])
            ->assertOk()
            ->assertJsonPath('data.template', 'formal');
    }

    public function test_an_unknown_template_is_rejected(): void
    {
        foreach (['fancy', '', null, 'term'] as $template) {
            $this->actingAs($this->admin, 'sanctum')
                ->putJson('/api/school/report-card-settings', ['head_teacher_name' => null, 'footer' => null, 'template' => $template])
                ->assertStatus(422)->assertJsonValidationErrors('template');
        }

        $this->assertFalse(SchoolSetting::query()->where('school_id', $this->school->id)->where('key', 'report_card.template')->exists());
    }

    public function test_a_stored_template_this_code_does_not_know_reads_as_classic(): void
    {
        SchoolSetting::create(['school_id' => $this->school->id, 'key' => 'report_card.template', 'value' => 'retired-design']);

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/school/report-card-settings')->assertJsonPath('data.template', 'classic');
    }

    public function test_one_schools_template_never_touches_anothers(): void
    {
        SchoolSetting::create(['school_id' => $this->other->id, 'key' => 'report_card.template', 'value' => 'formal']);

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/school/report-card-settings')->assertJsonPath('data.template', 'classic');

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/school/report-card-settings', ['head_teacher_name' => null, 'footer' => null, 'template' => 'modern'])
            ->assertOk();

        $this->assertSame('formal', SchoolSetting::query()->where('school_id', $this->other->id)->where('key', 'report_card.template')->value('value'));
    }

    public function test_a_class_teacher_cannot_choose_or_preview_a_template(): void
    {
        $this->actingAs($this->teacher, 'sanctum')
            ->putJson('/api/school/report-card-settings', ['head_teacher_name' => null, 'footer' => null, 'template' => 'modern'])
            ->assertStatus(403);
        $this->actingAs($this->teacher, 'sanctum')->get('/api/school/report-card-templates/classic/preview')->assertStatus(403);

        $this->assertFalse(SchoolSetting::query()->where('school_id', $this->school->id)->where('key', 'report_card.template')->exists());
    }
}
