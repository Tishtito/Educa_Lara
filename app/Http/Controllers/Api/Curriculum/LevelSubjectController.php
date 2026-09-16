<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Curriculum;

use App\Domain\Curriculum\DefaultCurriculum;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Curriculum\Services\CurriculumService;
use App\Domain\Tenancy\TenantManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\Curriculum\LevelSubjectRequest;
use App\Http\Resources\LevelSubjectResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** What each level teaches: papers, how they combine, grading, order on the mark list. */
class LevelSubjectController extends Controller
{
    public function __construct(private readonly CurriculumService $curriculum) {}

    public function index(Request $request): JsonResponse
    {
        $levelSubjects = LevelSubject::query()
            ->with(['subject', 'papers'])
            ->when($request->integer('level_id'), fn ($q, $id) => $q->where('level_id', $id))
            ->orderBy('level_id')
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        $frozen = $this->curriculum->frozenIds($levelSubjects->pluck('id'));
        $levelSubjects->each(fn (LevelSubject $ls) => $ls->setAttribute('in_use', $frozen->contains($ls->id)));

        return response()->json(['success' => true, 'data' => LevelSubjectResource::collection($levelSubjects)->resolve()]);
    }

    public function store(LevelSubjectRequest $request, TenantManager $tenancy): JsonResponse
    {
        $data = $request->validated();
        $levelSubject = $this->curriculum->create($tenancy->currentOrFail()->getKey(), $data, $data['papers']);
        $levelSubject->setAttribute('in_use', false);

        return response()->json(['success' => true, 'data' => new LevelSubjectResource($levelSubject)], 201);
    }

    /**
     * POST /level-subjects/defaults — add the standard CBC subjects for each
     * level that the school does not offer yet. Nothing already set up changes.
     */
    public function applyDefaults(DefaultCurriculum $defaults, TenantManager $tenancy): JsonResponse
    {
        $added = $defaults->apply($tenancy->currentOrFail());

        return response()->json([
            'success' => true,
            'message' => $added['level_subjects_added'] === 0
                ? 'Every default subject is already set up.'
                : "Added {$added['level_subjects_added']} subjects across the levels.",
            'data' => $added,
        ]);
    }

    public function update(LevelSubjectRequest $request, LevelSubject $levelSubject): JsonResponse
    {
        $data = $request->validated();
        $updated = $this->curriculum->update($levelSubject, $data, $data['papers'] ?? null);
        $updated->setAttribute('in_use', $this->curriculum->isFrozen($updated));

        return response()->json(['success' => true, 'data' => new LevelSubjectResource($updated)]);
    }

    public function destroy(LevelSubject $levelSubject): JsonResponse
    {
        $this->curriculum->delete($levelSubject->load('subject'));

        return response()->json(['success' => true, 'message' => 'Subject removed from this level.']);
    }
}
