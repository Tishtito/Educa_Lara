<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\Grade;
use App\Domain\Academics\Models\Level;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Stream;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Curriculum\Models\Subject;
use App\Domain\People\Models\ClassTeacherAssignment;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\User;
use App\Domain\Resources\Models\TeachingMaterial;
use App\Domain\Tenancy\Models\School;
use App\Domain\Timetabling\Enums\TimetableStatus;
use App\Domain\Timetabling\Models\Timetable;
use App\Domain\Timetabling\Models\TimetableLesson;
use Database\Seeders\DemoSchoolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Teaching materials against the demo schools: Gatimu's `teacher` is class
 * teacher of Grade 5 Blue (upper primary) this year. Files go to a faked
 * private disk.
 */
class TeachingMaterialsApiTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private School $other;

    private User $admin;

    private User $teacher;

    private Level $upper;

    private Level $junior;

    /** @var array<string, int> grade code => id */
    private array $grades;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['elimupass.materials.disk' => 'local']);

        $this->seedReferenceData();
        $this->school = (new DemoSchoolSeeder)->run('Gatimu Primary', 'gatimu', 'gatimu.educa.test');
        $this->other = (new DemoSchoolSeeder)->run('Riverside Academy', 'riverside', 'riverside.educa.test');
        $this->forgetTenant();

        $this->admin = User::query()->where('username', 'admin')->firstOrFail();
        $this->teacher = User::query()->where('username', 'teacher')->firstOrFail();
        $this->upper = Level::query()->where('code', Level::UPPER_PRIMARY)->firstOrFail();
        $this->junior = Level::query()->where('code', Level::JUNIOR_SECONDARY)->firstOrFail();
        $this->grades = Grade::query()->pluck('id', 'code')->map(fn ($id) => (int) $id)->all();
    }

    private function upload(array $fields = [], ?UploadedFile $file = null, ?User $as = null): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($as ?? $this->admin, 'sanctum')->post('/api/teaching-materials', [
            'file' => $file ?? UploadedFile::fake()->createWithContent('Grade 5 Maths scheme.pdf', '%PDF-1.4 synthetic'),
            'level_id' => $this->upper->id,
            ...$fields,
        ], ['Accept' => 'application/json']);
    }

    private function material(array $attributes = [], ?School $school = null): TeachingMaterial
    {
        $school ??= $this->school;
        $material = TeachingMaterial::factory()->create([
            'school_id' => $school->id,
            'level_id' => $this->upper->id,
            ...$attributes,
        ]);
        Storage::disk('local')->put($material->path, 'bytes of '.$material->title);

        return $material;
    }

    /** @param  list<string>  $codes */
    private function tag(TeachingMaterial $material, array $codes): TeachingMaterial
    {
        $material->grades()->sync(array_map(fn ($c) => $this->grades[$c], $codes));

        return $material;
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'sanctum');
    }

    public function test_an_admin_uploads_a_pdf_for_two_grades(): void
    {
        $response = $this->upload(['grade_ids' => [$this->grades['G5'], $this->grades['G6']], 'description' => 'Term 1'])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Grade 5 Maths scheme')
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.extension', 'pdf')
            ->assertJsonPath('data.level.code', Level::UPPER_PRIMARY)
            ->assertJsonPath('data.grades.*.code', ['G5', 'G6'])
            ->assertJsonPath('duplicate_of', null)
            ->assertJsonMissingPath('data.path');

        $material = TeachingMaterial::acrossTenants()->findOrFail($response->json('data.id'));
        $this->assertSame($this->school->id, $material->school_id);
        $this->assertSame("schools/{$this->school->uuid}/materials/{$material->uuid}.pdf", $material->path);
        Storage::disk('local')->assertExists($material->path);
        $this->assertSame($this->admin->id, $material->uploaded_by);
        $this->assertNotNull($material->published_at);
    }

    public function test_a_second_copy_of_the_same_file_is_flagged_but_saved(): void
    {
        $first = $this->upload(['title' => 'Original'])->assertCreated()->json('data.id');

        $this->upload(['status' => 'draft'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.published_at', null)
            ->assertJsonPath('duplicate_of.id', $first);
    }

    public function test_uploads_are_refused_for_wrong_types_sizes_grades_and_subjects(): void
    {
        $this->upload([], UploadedFile::fake()->create('virus.pdf', 10, 'application/x-msdownload'))
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->upload([], UploadedFile::fake()->create('photo.png', 10, 'application/pdf'))
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->upload([], UploadedFile::fake()->create('huge.pdf', 20481, 'application/pdf'))
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->upload(['grade_ids' => [$this->grades['G8']]])
            ->assertUnprocessable()->assertJsonValidationErrors('grade_ids');

        $theirs = LevelSubject::acrossTenants()->where('school_id', $this->other->id)->where('level_id', $this->upper->id)->firstOrFail();
        $this->upload(['level_subject_id' => $theirs->id])
            ->assertUnprocessable()->assertJsonValidationErrors('level_subject_id');

        $jssSubject = LevelSubject::factory()->create([
            'school_id' => $this->school->id,
            'level_id' => $this->junior->id,
            'subject_id' => Subject::factory()->create(['school_id' => $this->school->id]),
        ]);
        $this->upload(['level_subject_id' => $jssSubject->id])
            ->assertUnprocessable()->assertJsonValidationErrors('level_subject_id');

        $this->assertSame(0, TeachingMaterial::acrossTenants()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_office_files_and_images_are_accepted(): void
    {
        $this->upload([], UploadedFile::fake()->create('notes.docx', 50, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'))->assertCreated();
        $this->upload([], UploadedFile::fake()->create('old.ppt', 50, 'application/vnd.ms-office'))->assertCreated();
        $this->upload([], UploadedFile::fake()->create('chart.png', 50, 'image/png'))->assertCreated();
    }

    public function test_filtering_by_a_grade_includes_whole_section_materials(): void
    {
        $g5 = $this->tag($this->material(['title' => 'Grade 5 only']), ['G5']);
        $section = $this->material(['title' => 'Upper primary handbook']);
        $g6 = $this->tag($this->material(['title' => 'Grade 6 only']), ['G6']);
        $lower = $this->material(['title' => 'Lower primary handbook', 'level_id' => Level::query()->where('code', Level::LOWER_PRIMARY)->value('id')]);

        $ids = $this->as($this->admin)->getJson("/api/teaching-materials?grade_id={$this->grades['G5']}")
            ->assertOk()->json('data.*.id');
        $this->assertEqualsCanonicalizing([$g5->id, $section->id], $ids);

        $summary = collect($this->as($this->admin)->getJson('/api/teaching-materials/summary')->assertOk()->json('data.levels'))->keyBy('code');
        $this->assertSame(3, $summary[Level::UPPER_PRIMARY]['count']);
        $this->assertSame(1, $summary[Level::LOWER_PRIMARY]['count']);
        $this->assertSame([1, 2, 2], array_column($summary[Level::UPPER_PRIMARY]['grades'], 'count'));   // G4, G5, G6
        unset($g6, $lower);
    }

    public function test_teachers_cannot_reach_the_admin_routes(): void
    {
        $material = $this->material();

        $this->upload([], null, $this->teacher)->assertForbidden();
        foreach ([
            ['GET', '/api/teaching-materials'],
            ['GET', '/api/teaching-materials/summary'],
            ['PATCH', "/api/teaching-materials/{$material->id}"],
            ['POST', "/api/teaching-materials/{$material->id}/file"],
            ['DELETE', "/api/teaching-materials/{$material->id}"],
            ['POST', "/api/teaching-materials/{$material->id}/restore"],
            ['GET', "/api/teaching-materials/{$material->id}/download"],
        ] as [$method, $url]) {
            $this->as($this->teacher)->json($method, $url)->assertForbidden();
        }
    }

    public function test_teachers_see_published_materials_only(): void
    {
        $published = $this->tag($this->material(['title' => 'Published']), ['G5']);
        $draft = $this->tag(TeachingMaterial::factory()->draft()->create(['school_id' => $this->school->id, 'level_id' => $this->upper->id]), ['G5']);
        $deleted = $this->tag($this->material(['title' => 'Deleted']), ['G5']);
        $deleted->delete();

        $this->as($this->teacher)->getJson('/api/my/teaching-materials')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$published->id])
            ->assertJsonPath('meta.my_grade_ids', [$this->grades['G5']]);

        $this->as($this->teacher)->get("/api/my/teaching-materials/{$draft->id}/download")->assertNotFound();
        $this->as($this->teacher)->get("/api/my/teaching-materials/{$deleted->id}/download")->assertNotFound();
        $this->as($this->teacher)->get("/api/my/teaching-materials/{$published->id}/download")->assertOk();
    }

    public function test_teachers_open_on_their_grades_and_can_browse_the_rest(): void
    {
        $mine = $this->tag($this->material(['title' => 'Grade 5']), ['G5']);
        $jss = $this->material(['title' => 'JSS handbook', 'level_id' => $this->junior->id]);

        $this->as($this->teacher)->getJson('/api/my/teaching-materials')->assertJsonPath('data.*.id', [$mine->id]);
        $this->as($this->teacher)->getJson("/api/my/teaching-materials?level_id={$this->junior->id}")->assertJsonPath('data.*.id', [$jss->id]);

        $this->as($this->teacher)->getJson('/api/my/teaching-materials/summary')
            ->assertOk()
            ->assertJsonPath('data.my_grade_ids', [$this->grades['G5']])
            ->assertJsonPath('data.new_for_my_grades', 1);
    }

    public function test_my_grades_come_from_this_years_classes_and_the_published_timetable(): void
    {
        $this->actingForSchool($this->school);
        $g6 = SchoolClass::factory()->create([
            'school_id' => $this->school->id,
            'grade_id' => $this->grades['G6'],
            'stream_id' => Stream::factory()->create(['school_id' => $this->school->id]),
        ]);
        $g7 = SchoolClass::factory()->create([
            'school_id' => $this->school->id,
            'grade_id' => $this->grades['G7'],
            'stream_id' => Stream::factory()->create(['school_id' => $this->school->id]),
        ]);

        // Last year's class does not count.
        $lastYear = AcademicYear::factory()->create(['school_id' => $this->school->id, 'name' => '2019']);
        ClassTeacherAssignment::factory()->create(['school_id' => $this->school->id, 'user_id' => $this->teacher->id, 'class_id' => $g7->id, 'academic_year_id' => $lastYear->id]);

        // A subject teacher placed only by the timetable generator.
        $subjectTeacher = User::factory()->withRole(Role::TEACHER)->create(['school_id' => $this->school->id]);
        $timetable = Timetable::query()->where('status', TimetableStatus::PUBLISHED)->firstOrFail();
        TimetableLesson::factory()->create([
            'school_id' => $this->school->id,
            'timetable_id' => $timetable->id,
            'class_id' => $g6->id,
            'level_subject_id' => LevelSubject::query()->where('level_id', $this->upper->id)->value('id'),
            'user_id' => $subjectTeacher->id,
        ]);
        $this->forgetTenant();

        $this->as($this->teacher)->getJson('/api/my/teaching-materials/summary')->assertJsonPath('data.my_grade_ids', [$this->grades['G5']]);
        $this->as($subjectTeacher)->getJson('/api/my/teaching-materials/summary')->assertJsonPath('data.my_grade_ids', [$this->grades['G6']]);
    }

    public function test_downloads_send_the_file_with_a_safe_name_and_headers(): void
    {
        $pdf = $this->material(['title' => 'Maths / "scheme" ../term 1']);
        $docx = $this->material(['title' => 'Notes', 'path' => 'materials-test/notes.docx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']);

        $response = $this->as($this->teacher)->get("/api/my/teaching-materials/{$pdf->id}/download")->assertOk();
        $this->assertSame('bytes of '.$pdf->title, $response->streamedContent());
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline; filename=maths-scheme-term-1.pdf', $response->headers->get('Content-Disposition'));

        $this->as($this->admin)->get("/api/teaching-materials/{$docx->id}/download")
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=notes.docx');
    }

    public function test_admins_edit_replace_delete_and_restore(): void
    {
        $id = $this->upload(['grade_ids' => [$this->grades['G5']]])->assertCreated()->json('data.id');
        $material = TeachingMaterial::acrossTenants()->findOrFail($id);
        $oldPath = $material->path;

        // Moving section clears grades that belonged to the old one.
        $this->as($this->admin)->patchJson("/api/teaching-materials/{$id}", ['level_id' => $this->junior->id, 'title' => 'Renamed'])
            ->assertOk()->assertJsonPath('data.grades', [])->assertJsonPath('data.title', 'Renamed');
        $this->as($this->admin)->patchJson("/api/teaching-materials/{$id}", ['grade_ids' => [$this->grades['G5']]])
            ->assertUnprocessable()->assertJsonValidationErrors('grade_ids');
        $this->as($this->admin)->patchJson("/api/teaching-materials/{$id}", ['title' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors('title');

        $this->as($this->admin)->post("/api/teaching-materials/{$id}/file", [
            'file' => UploadedFile::fake()->createWithContent('v2.pdf', '%PDF-1.4 version two'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.original_name', 'v2.pdf');

        $material->refresh();
        $this->assertNotSame($oldPath, $material->path);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($material->path);

        $this->as($this->admin)->deleteJson("/api/teaching-materials/{$id}")->assertOk();
        $this->as($this->admin)->getJson('/api/teaching-materials')->assertJsonPath('meta.total', 0);
        $this->as($this->admin)->getJson('/api/teaching-materials?deleted=1')->assertJsonPath('data.*.id', [$id]);
        Storage::disk('local')->assertExists($material->path);

        $this->as($this->admin)->postJson("/api/teaching-materials/{$id}/restore")->assertOk()->assertJsonPath('data.deleted_at', null);
        $this->as($this->admin)->getJson('/api/teaching-materials')->assertJsonPath('meta.total', 1);
    }

    public function test_prune_removes_materials_deleted_longer_ago_than_the_window(): void
    {
        $old = $this->material(['title' => 'Old']);
        $recent = $this->material(['title' => 'Recent']);

        $this->travel(-31)->days();
        $old->delete();
        $this->travelBack();
        $recent->delete();

        $this->artisan('materials:prune')->assertSuccessful();

        $this->assertNull(TeachingMaterial::acrossTenants()->withTrashed()->find($old->id));
        Storage::disk('local')->assertMissing($old->path);
        $this->assertNotNull(TeachingMaterial::acrossTenants()->withTrashed()->find($recent->id));
        Storage::disk('local')->assertExists($recent->path);
    }

    public function test_another_schools_material_is_not_found(): void
    {
        $theirs = $this->tag($this->material(['title' => 'Riverside notes'], $this->other), ['G5']);
        $this->material(['title' => 'Gatimu notes']);

        foreach ([
            ['PATCH', "/api/teaching-materials/{$theirs->id}", ['title' => 'Mine now']],
            ['DELETE', "/api/teaching-materials/{$theirs->id}", []],
            ['POST', "/api/teaching-materials/{$theirs->id}/restore", []],
            ['GET', "/api/teaching-materials/{$theirs->id}/download", []],
        ] as [$method, $url, $body]) {
            $this->as($this->admin)->json($method, $url, $body)->assertNotFound();
        }

        $this->as($this->teacher)->get("/api/my/teaching-materials/{$theirs->id}/download")->assertNotFound();
        $this->as($this->teacher)->getJson("/api/my/teaching-materials?level_id={$this->upper->id}")
            ->assertJsonPath('data.*.title', ['Gatimu notes']);
        $this->as($this->admin)->getJson('/api/teaching-materials')->assertJsonPath('data.*.title', ['Gatimu notes']);
        $this->assertSame('Riverside notes', $theirs->refresh()->title);
    }
}
