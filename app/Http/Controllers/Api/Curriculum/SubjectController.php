<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Curriculum;

use App\Domain\Curriculum\Models\Subject;
use App\Domain\Curriculum\Services\CurriculumService;
use App\Domain\Tenancy\TenantManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\Curriculum\SubjectRequest;
use App\Http\Resources\SubjectResource;
use Illuminate\Http\JsonResponse;

/** The school's subject catalogue. What each level teaches is LevelSubjectController. */
class SubjectController extends Controller
{
    public function __construct(private readonly CurriculumService $curriculum) {}

    public function index(): JsonResponse
    {
        $subjects = Subject::query()->with('levelSubjects')->orderBy('name')->get();

        return response()->json(['success' => true, 'data' => SubjectResource::collection($subjects)->resolve()]);
    }

    public function store(SubjectRequest $request, TenantManager $tenancy): JsonResponse
    {
        $subject = Subject::create($request->validated() + ['school_id' => $tenancy->currentOrFail()->getKey()]);

        return response()->json(['success' => true, 'data' => new SubjectResource($subject->load('levelSubjects'))], 201);
    }

    public function update(SubjectRequest $request, Subject $subject): JsonResponse
    {
        $subject->fill($request->validated())->save();

        return response()->json(['success' => true, 'data' => new SubjectResource($subject->load('levelSubjects'))]);
    }

    public function destroy(Subject $subject): JsonResponse
    {
        $this->curriculum->deleteSubject($subject);

        return response()->json(['success' => true, 'message' => 'Subject deleted.']);
    }
}
