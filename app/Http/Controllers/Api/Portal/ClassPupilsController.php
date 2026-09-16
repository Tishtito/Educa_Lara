<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\People\ClassTeachingAccess;
use App\Domain\People\Models\Student;
use App\Domain\People\Services\StudentResults;
use App\Domain\People\Services\StudentService;
use App\Domain\Tenancy\TenantManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\People\AdmitToClassRequest;
use App\Http\Resources\StudentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A class teacher's pupils. Teachers admit pupils into their own class and move
 * pupils out of it; deleting pupils and changing their status stay with the
 * school administrator.
 */
class ClassPupilsController extends Controller
{
    public function __construct(
        private readonly ClassTeachingAccess $access,
        private readonly StudentService $students,
    ) {}

    /** GET /my/classes/{schoolClass}/students?academic_year_id= */
    public function index(Request $request, SchoolClass $schoolClass): JsonResponse
    {
        $yearId = $request->integer('academic_year_id') ?: $this->access->currentYearId();

        if (! $this->access->teachesClass($request->user(), $schoolClass->id, $yearId)) {
            return $this->forbidden('You can only see the pupils of classes you teach.');
        }

        $pupils = Student::query()
            ->whereHas('enrolments', fn ($q) => $q->where('academic_year_id', $yearId)->where('class_id', $schoolClass->id))
            ->with(['enrolments' => fn ($q) => $q->where('academic_year_id', $yearId)->with('schoolClass')])
            ->orderBy('full_name')
            ->get()
            ->each(fn (Student $s) => $s->setRelation('enrolment', $s->enrolments->first())->unsetRelation('enrolments'));

        return response()->json(['success' => true, 'data' => [
            'class' => ['id' => $schoolClass->id, 'name' => $schoolClass->name],
            'academic_year_id' => $yearId,
            'students' => StudentResource::collection($pupils)->resolve(),
        ]]);
    }

    /** POST /my/classes/{schoolClass}/students — admit a pupil into this class, this year. */
    public function store(AdmitToClassRequest $request, SchoolClass $schoolClass, TenantManager $tenancy): JsonResponse
    {
        $yearId = $this->access->currentYearId();

        if ($yearId === null) {
            return response()->json(['success' => false, 'message' => 'The school has no current academic year yet.'], 409);
        }

        if (! $this->access->teachesClass($request->user(), $schoolClass->id, $yearId)) {
            return $this->forbidden('You can only admit pupils into a class you teach.');
        }

        $student = $this->students->admit($tenancy->currentOrFail()->getKey(), $request->validated(), $schoolClass->id, $yearId);

        return response()->json(['success' => true, 'data' => new StudentResource($student->load('enrolments.academicYear', 'enrolments.schoolClass'))], 201);
    }

    /** POST /my/students/{student}/move {class_id} */
    public function move(Request $request, Student $student, TenantManager $tenancy): JsonResponse
    {
        $data = $request->validate([
            'class_id' => ['required', 'integer', Rule::exists('classes', 'id')
                ->where('school_id', $tenancy->currentOrFail()->getKey())->whereNull('deleted_at')->where('is_active', true)],
        ]);

        if (! $this->access->teachesPupilNow($request->user(), $student)) {
            return $this->forbidden('You can only move pupils who are in your class.');
        }

        $this->students->move($student, SchoolClass::query()->findOrFail($data['class_id']), (int) $this->access->currentYearId());

        // Returned without the read check: once moved, the pupil is no longer in
        // this teacher's class, which is the point of the move.
        return response()->json(['success' => true, 'data' => new StudentResource($student->fresh()->load([
            'enrolments' => fn ($q) => $q->with(['academicYear', 'schoolClass'])->orderByDesc('academic_year_id'),
        ]))]);
    }

    /** GET /my/students/{student} */
    public function show(Request $request, Student $student): JsonResponse
    {
        if (! $this->access->hasTaughtPupil($request->user(), $student)) {
            return $this->forbidden('You can only see pupils you teach or have taught.');
        }

        return response()->json(['success' => true, 'data' => new StudentResource($student->load([
            'enrolments' => fn ($q) => $q->with(['academicYear', 'schoolClass'])->orderByDesc('academic_year_id'),
        ]))]);
    }

    /** GET /my/students/{student}/results */
    public function results(Request $request, Student $student, StudentResults $results): JsonResponse
    {
        if (! $this->access->hasTaughtPupil($request->user(), $student)) {
            return $this->forbidden('You can only see pupils you teach or have taught.');
        }

        return response()->json(['success' => true, 'data' => $results->for($student)->values()]);
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], 403);
    }
}
