<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\People;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\People\Models\ExaminerAssignment;
use App\Domain\People\Services\AssignmentService;
use App\Domain\Tenancy\TenantManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AssignmentController extends Controller
{
    public function __construct(
        private readonly AssignmentService $assignments,
        private readonly TenantManager $tenancy,
    ) {}

    /** GET /class-teacher-assignments?academic_year_id= (default: current year) */
    public function classTeachers(Request $request): JsonResponse
    {
        $yearId = $this->yearId($request);

        return response()->json([
            'success' => true,
            'data' => [
                'academic_year_id' => $yearId,
                'assignments' => $this->assignments->classTeachers($yearId ?? 0)->map(fn ($a) => [
                    'id' => $a->id,
                    'class_id' => $a->class_id,
                    'class_name' => $a->schoolClass?->name,
                    'user_id' => $a->user_id,
                    'user_name' => $a->user?->name,
                    'is_primary' => $a->is_primary,
                ])->values(),
            ],
        ]);
    }

    /** PUT /classes/{schoolClass}/class-teachers {academic_year_id, user_ids} */
    public function setClassTeachers(Request $request, SchoolClass $schoolClass): JsonResponse
    {
        $data = $request->validate([
            'academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')->where('school_id', $this->schoolId())],
            'user_ids' => ['present', 'array', 'max:5'],
            'user_ids.*' => ['integer', 'distinct'],
        ]);

        $this->assignments->setClassTeachers($schoolClass, $data['academic_year_id'], array_map('intval', $data['user_ids']), $request->user()->id);

        return $this->classTeachers($request);
    }

    /** GET /examiner-assignments?academic_year_id=&class_id=&user_id= */
    public function examiners(Request $request): JsonResponse
    {
        $yearId = $this->yearId($request);

        $rows = ExaminerAssignment::query()
            ->where('academic_year_id', $yearId ?? 0)
            ->when($request->integer('class_id'), fn ($q, $id) => $q->where('class_id', $id))
            ->when($request->integer('user_id'), fn ($q, $id) => $q->where('user_id', $id))
            ->with(['user', 'schoolClass', 'levelSubject.subject'])
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'academic_year_id' => $yearId,
                'assignments' => $rows->map(fn (ExaminerAssignment $a) => [
                    'id' => $a->id,
                    'class_id' => $a->class_id,
                    'class_name' => $a->schoolClass?->name,
                    'level_subject_id' => $a->level_subject_id,
                    'subject_name' => $a->levelSubject?->subject?->name,
                    'user_id' => $a->user_id,
                    'user_name' => $a->user?->name,
                    'term_id' => $a->term_id,
                    // Inferred from a legacy class_assigned string: worth reviewing.
                    'inferred' => $a->wasInferred(),
                ])->values(),
            ],
        ]);
    }

    /** PUT /classes/{schoolClass}/examiners {academic_year_id, subjects: [{level_subject_id, user_ids, term_id?}]} */
    public function setExaminers(Request $request, SchoolClass $schoolClass): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')->where('school_id', $schoolId)],
            'subjects' => ['required', 'array', 'min:1', 'max:30'],
            'subjects.*.level_subject_id' => ['required', 'integer', 'distinct'],
            'subjects.*.user_ids' => ['present', 'array', 'max:10'],
            'subjects.*.user_ids.*' => ['integer'],
            'subjects.*.term_id' => ['sometimes', 'nullable', 'integer', Rule::exists('terms', 'id')->where('school_id', $schoolId)],
        ]);

        $this->assignments->setExaminers($schoolClass, $data['academic_year_id'], $data['subjects'], $request->user()->id);

        $request->merge(['class_id' => $schoolClass->id]);

        return $this->examiners($request);
    }

    /** POST /assignments/copy-year {from_year_id, to_year_id} */
    public function copyYear(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'from_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')->where('school_id', $schoolId)],
            'to_year_id' => ['required', 'integer', 'different:from_year_id', Rule::exists('academic_years', 'id')->where('school_id', $schoolId)],
        ]);

        return response()->json(['success' => true, 'data' => $this->assignments->copyYear($schoolId, $data['from_year_id'], $data['to_year_id'])]);
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
