<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Timetabling;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\TenantManager;
use App\Domain\Timetabling\Services\TeacherSubjectService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The subjects each teacher can teach (GET /teacher-subjects,
 * PUT /staff/{user}/teacher-subjects). The timetable generator gives classes
 * to these teachers; a pinned teaching assignment overrides it.
 */
class TeacherSubjectController extends Controller
{
    public function __construct(
        private readonly TeacherSubjectService $subjects,
        private readonly TenantManager $tenancy,
    ) {}

    /** GET /teacher-subjects?academic_year_id= (default: the current year) */
    public function index(Request $request): JsonResponse
    {
        $yearId = $request->integer('academic_year_id') ?: AcademicYear::query()->where('is_current', true)->value('id');

        if ($yearId !== null && ! AcademicYear::query()->whereKey($yearId)->exists()) {
            abort(404);
        }

        return response()->json(['success' => true, 'data' => $this->payload($yearId)]);
    }

    /** PUT /staff/{user}/teacher-subjects {academic_year_id, level_subject_ids: []} */
    public function update(Request $request, int $user): JsonResponse
    {
        // User is not tenant-scoped, so it is never route-bound: found here
        // with the school named, it 404s for another school's staff.
        $teacher = User::query()->where('school_id', $this->schoolId())->findOrFail($user);
        $data = $request->validate([
            'academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')->where('school_id', $this->schoolId())],
            'level_subject_ids' => ['present', 'array', 'max:200'],
            'level_subject_ids.*' => ['integer', 'distinct'],
        ]);

        $this->subjects->set($teacher, (int) $data['academic_year_id'], array_map('intval', $data['level_subject_ids']));

        return response()->json(['success' => true, 'data' => $this->payload((int) $data['academic_year_id'])]);
    }

    /** POST /teacher-subjects/copy-year {from_year_id, to_year_id} */
    public function copyYear(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'from_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')->where('school_id', $schoolId)],
            'to_year_id' => ['required', 'integer', 'different:from_year_id', Rule::exists('academic_years', 'id')->where('school_id', $schoolId)],
        ]);

        return response()->json(['success' => true, 'data' => ['created' => $this->subjects->copyYear((int) $data['from_year_id'], (int) $data['to_year_id'])]]);
    }

    /** POST /teacher-subjects/from-examiners {academic_year_id} */
    public function fromExaminers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')->where('school_id', $this->schoolId())],
        ]);

        return response()->json(['success' => true, 'data' => $this->subjects->fromExaminers((int) $data['academic_year_id'])]);
    }

    private function payload(?int $yearId): array
    {
        if ($yearId === null) {
            return ['academic_year_id' => null, 'teachers' => [], 'subjects' => []];
        }

        return [
            'academic_year_id' => $yearId,
            'teachers' => $this->subjects->list($yearId)->map(fn (array $row) => [
                'user_id' => $row['user']->id,
                'name' => $row['user']->name,
                'is_active' => (bool) $row['user']->is_active,
                'level_subject_ids' => $row['level_subject_ids'],
            ])->values(),
            'subjects' => $this->subjects->subjects($yearId)->map(fn (array $row) => [
                'level_subject_id' => $row['subject']->id,
                'level_id' => $row['subject']->level_id,
                'level_name' => $row['subject']->level?->name,
                'level_code' => $row['subject']->level?->code,
                'subject_name' => $row['subject']->subject?->name,
                'subject_code' => $row['subject']->subject?->code,
                'teachers_count' => $row['teachers_count'],
            ])->values(),
        ];
    }

    private function schoolId(): int
    {
        return (int) $this->tenancy->currentOrFail()->getKey();
    }
}
