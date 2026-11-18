<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Timetabling;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Tenancy\TenantManager;
use App\Domain\Timetabling\Models\TeachingAssignment;
use App\Domain\Timetabling\Services\TeachingAssignmentService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Who teaches each subject to each class (GET /teaching-assignments, PUT /classes/{class}/teaching-assignments). */
class TeachingAssignmentController extends Controller
{
    public function __construct(
        private readonly TeachingAssignmentService $assignments,
        private readonly TenantManager $tenancy,
    ) {}

    /** GET /teaching-assignments?academic_year_id=&class_id=&user_id= */
    public function index(Request $request): JsonResponse
    {
        $yearId = $this->yearId($request);
        $rows = $this->assignments->list($yearId ?? 0, $request->integer('class_id') ?: null, $request->integer('user_id') ?: null);

        return response()->json(['success' => true, 'data' => [
            'academic_year_id' => $yearId,
            'assignments' => $rows->map(fn (TeachingAssignment $a) => [
                'id' => $a->id,
                'class_id' => $a->class_id,
                'class_name' => $a->schoolClass?->name,
                'level_subject_id' => $a->level_subject_id,
                'subject_name' => $a->levelSubject?->subject?->name,
                'user_id' => $a->user_id,
                'user_name' => $a->user?->name,
                'user_active' => (bool) $a->user?->is_active,
            ])->values(),
        ]]);
    }

    /** PUT /classes/{schoolClass}/teaching-assignments {academic_year_id, subjects: [{level_subject_id, user_id|null}]} */
    public function update(Request $request, SchoolClass $schoolClass): JsonResponse
    {
        $data = $request->validate([
            'academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')->where('school_id', $this->schoolId())],
            'subjects' => ['required', 'array', 'min:1', 'max:40'],
            'subjects.*.level_subject_id' => ['required', 'integer', 'distinct'],
            'subjects.*.user_id' => ['present', 'nullable', 'integer'],
        ]);

        $this->assignments->set($schoolClass, (int) $data['academic_year_id'], $data['subjects'], $request->user()->id);
        $request->merge(['class_id' => $schoolClass->id]);

        return $this->index($request);
    }

    /** POST /teaching-assignments/from-examiners {academic_year_id} */
    public function fromExaminers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')->where('school_id', $this->schoolId())],
        ]);

        return response()->json(['success' => true, 'data' => $this->assignments->fromExaminers((int) $data['academic_year_id'], $request->user()->id)]);
    }

    /** POST /teaching-assignments/copy-year {from_year_id, to_year_id} */
    public function copyYear(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'from_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')->where('school_id', $schoolId)],
            'to_year_id' => ['required', 'integer', 'different:from_year_id', Rule::exists('academic_years', 'id')->where('school_id', $schoolId)],
        ]);

        return response()->json(['success' => true, 'data' => ['created' => $this->assignments->copyYear((int) $data['from_year_id'], (int) $data['to_year_id'])]]);
    }

    private function yearId(Request $request): ?int
    {
        return $request->integer('academic_year_id') ?: AcademicYear::query()->where('is_current', true)->value('id');
    }

    private function schoolId(): int
    {
        return $this->tenancy->currentOrFail()->getKey();
    }
}
