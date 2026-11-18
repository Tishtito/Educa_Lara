<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\People;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\People\Models\Student;
use App\Domain\People\Services\StudentResults;
use App\Domain\People\Services\StudentService;
use App\Domain\Tenancy\TenantManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\People\StudentRequest;
use App\Http\Resources\StudentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StudentController extends Controller
{
    public function __construct(
        private readonly StudentService $students,
        private readonly TenantManager $tenancy,
    ) {}

    /**
     * GET /students?search=&status=&class_id=&academic_year_id=&unenrolled=1&page=&per_page=
     * Enrolment filters and the `enrolment` shown are for the given year (default: current).
     */
    public function index(Request $request): JsonResponse
    {
        $yearId = $request->integer('academic_year_id') ?: (int) AcademicYear::query()->where('is_current', true)->value('id');
        $search = $request->string('search')->trim()->toString();

        $page = Student::query()
            ->with(['enrolments' => fn ($q) => $q->where('academic_year_id', $yearId)->with('schoolClass')])
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('full_name', 'like', "%{$search}%")
                ->orWhere('assessment_no', 'like', "%{$search}%")))
            ->when($request->string('status')->toString(), fn ($q, $status) => $q->where('status', $status))
            ->when($request->integer('class_id'), fn ($q, $classId) => $q->whereHas('enrolments', fn ($q) => $q
                ->where('academic_year_id', $yearId)->where('class_id', $classId)))
            ->when($request->boolean('unenrolled'), fn ($q) => $q->whereDoesntHave('enrolments', fn ($q) => $q->where('academic_year_id', $yearId)))
            ->orderBy('full_name')
            ->paginate(min(max($request->integer('per_page') ?: 50, 1), 200));

        $page->getCollection()->each(fn (Student $s) => $s->setRelation('enrolment', $s->enrolments->first())->unsetRelation('enrolments'));

        return response()->json([
            'success' => true,
            'data' => StudentResource::collection($page->getCollection())->resolve(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'academic_year_id' => $yearId ?: null,
            ],
        ]);
    }

    public function store(StudentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $yearId = $data['academic_year_id'] ?? AcademicYear::query()->where('is_current', true)->value('id');

        if ($yearId === null) {
            return response()->json(['success' => false, 'message' => 'Set a current academic year before admitting pupils.'], 409);
        }

        $student = $this->students->admit($this->tenancy->currentOrFail()->getKey(), $data, (int) $data['class_id'], (int) $yearId);

        return response()->json(['success' => true, 'data' => new StudentResource($student->load('enrolments.academicYear', 'enrolments.schoolClass'))], 201);
    }

    public function show(Student $student): JsonResponse
    {
        return response()->json(['success' => true, 'data' => new StudentResource($student->load([
            'enrolments' => fn ($q) => $q->with(['academicYear', 'schoolClass'])->orderByDesc('academic_year_id'),
        ]))]);
    }

    public function update(StudentRequest $request, Student $student): JsonResponse
    {
        $student->fill($this->students->studentAttributes($request->validated()))->save();

        return $this->show($student);
    }

    /** POST /students/{student}/move {class_id, academic_year_id?} */
    public function move(Request $request, Student $student): JsonResponse
    {
        $schoolId = $this->tenancy->currentOrFail()->getKey();
        $data = $request->validate([
            'class_id' => ['required', 'integer', Rule::exists('classes', 'id')->where('school_id', $schoolId)->whereNull('deleted_at')],
            'academic_year_id' => ['sometimes', 'integer', Rule::exists('academic_years', 'id')->where('school_id', $schoolId)],
        ]);
        $yearId = $data['academic_year_id'] ?? AcademicYear::query()->where('is_current', true)->value('id');

        if ($yearId === null) {
            return response()->json(['success' => false, 'message' => 'Set a current academic year first.'], 409);
        }

        $this->students->move($student, SchoolClass::query()->findOrFail($data['class_id']), (int) $yearId);

        return $this->show($student->fresh());
    }

    /** POST /students/{student}/status {status, ended_on?} */
    public function status(Request $request, Student $student): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'transferred', 'graduated', 'inactive'])],
            'ended_on' => ['sometimes', 'nullable', 'date'],
        ]);

        $this->students->changeStatus($student, $data['status'], $data['ended_on'] ?? null);

        return $this->show($student->fresh());
    }

    public function destroy(Student $student): JsonResponse
    {
        $this->students->delete($student);

        return response()->json(['success' => true, 'message' => 'Pupil deleted.']);
    }

    /** GET /students/{student}/results */
    public function results(Student $student, StudentResults $results): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $results->for($student)->values()]);
    }
}
