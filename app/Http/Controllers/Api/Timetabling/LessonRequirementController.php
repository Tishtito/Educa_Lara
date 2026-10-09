<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Timetabling;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\Grade;
use App\Domain\Tenancy\TenantManager;
use App\Domain\Timetabling\Services\LessonRequirementService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Lessons per week: GET/PUT /timetable/requirements. */
class LessonRequirementController extends Controller
{
    public function __construct(
        private readonly LessonRequirementService $requirements,
        private readonly TenantManager $tenancy,
    ) {}

    /** GET /timetable/requirements?grade_id=&academic_year_id= (default: current year) */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'grade_id' => ['required', 'integer', Rule::exists('grades', 'id')],
            'academic_year_id' => ['sometimes', 'integer', Rule::exists('academic_years', 'id')->where('school_id', $this->schoolId())],
        ]);

        // Query-string values arrive as strings; the `integer` rule checks them but does not convert.
        $yearId = isset($data['academic_year_id']) ? (int) $data['academic_year_id'] : AcademicYear::query()->where('is_current', true)->value('id');

        return response()->json(['success' => true, 'data' => $yearId === null ? null : $this->requirements->forGrade((int) $yearId, Grade::query()->findOrFail((int) $data['grade_id']))]);
    }

    /** PUT /timetable/requirements {academic_year_id, grade_id, requirements: [{level_subject_id, lessons_per_week, max_per_day, double_lessons}]} */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')->where('school_id', $this->schoolId())],
            'grade_id' => ['required', 'integer', Rule::exists('grades', 'id')],
            'requirements' => ['present', 'array', 'max:40'],
            'requirements.*.level_subject_id' => ['required', 'integer', 'distinct'],
            'requirements.*.lessons_per_week' => ['required', 'integer', 'between:0,20'],
            'requirements.*.max_per_day' => ['sometimes', 'integer', 'between:1,10'],
            'requirements.*.double_lessons' => ['sometimes', 'integer', 'between:0,10'],
        ]);

        $grade = Grade::query()->findOrFail((int) $data['grade_id']);
        $this->requirements->save((int) $data['academic_year_id'], $grade, $data['requirements']);

        return response()->json(['success' => true, 'data' => $this->requirements->forGrade((int) $data['academic_year_id'], $grade)]);
    }

    /** POST /timetable/requirements/copy-year {from_year_id, to_year_id} */
    public function copyYear(Request $request): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'from_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')->where('school_id', $schoolId)],
            'to_year_id' => ['required', 'integer', 'different:from_year_id', Rule::exists('academic_years', 'id')->where('school_id', $schoolId)],
        ]);

        return response()->json(['success' => true, 'data' => ['created' => $this->requirements->copyYear((int) $data['from_year_id'], (int) $data['to_year_id'])]]);
    }

    private function schoolId(): int
    {
        return $this->tenancy->currentOrFail()->getKey();
    }
}
