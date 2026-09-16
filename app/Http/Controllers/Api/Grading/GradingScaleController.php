<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Grading;

use App\Domain\Academics\Exceptions\SetupConflict;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Grading\Models\GradingScale;
use App\Domain\Grading\Services\GradingScaleService;
use App\Domain\Tenancy\TenantManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\Grading\GradingScaleRequest;
use App\Http\Resources\GradingScaleResource;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

/**
 * Grading scales visible to a school: the platform scales every school shares
 * (read-only) and the school's own. GradingScale is not tenant-scoped (platform
 * rows have no school), so every lookup here filters by school explicitly.
 */
class GradingScaleController extends Controller
{
    public function __construct(
        private readonly GradingScaleService $scales,
        private readonly TenantManager $tenancy,
    ) {}

    public function index(): JsonResponse
    {
        $schoolId = $this->schoolId();

        $scales = GradingScale::query()
            ->with('bands')
            ->where('legacy_broken', false)
            ->where(fn ($q) => $q->whereNull('school_id')->orWhere('school_id', $schoolId))
            ->orderByRaw('school_id IS NULL')
            ->orderBy('level_id')
            ->orderBy('name')
            ->get();

        $usage = LevelSubject::query()
            ->whereIn('grading_scale_id', $scales->pluck('id'))
            ->selectRaw('grading_scale_id, COUNT(*) as uses')
            ->groupBy('grading_scale_id')
            ->pluck('uses', 'grading_scale_id');

        $scales->each(fn (GradingScale $s) => $s->setAttribute('level_subjects_count', (int) ($usage[$s->id] ?? 0)));

        return response()->json(['success' => true, 'data' => GradingScaleResource::collection($scales)->resolve()]);
    }

    public function store(GradingScaleRequest $request): JsonResponse
    {
        $data = $request->validated();
        $scale = $this->scales->create($this->schoolId(), $data, $data['bands']);

        return response()->json(['success' => true, 'data' => new GradingScaleResource($scale)], 201);
    }

    public function update(GradingScaleRequest $request, int $gradingScale): JsonResponse
    {
        $scale = $this->find($gradingScale);

        if ($scale->school_id === null) {
            throw SetupConflict::inUse("\"{$scale->name}\"", 'it is the platform scale shared by every school', 'Copy it to your school and edit the copy.');
        }

        $scale->fill($request->safe()->only(['name', 'kind']))->save();

        return response()->json(['success' => true, 'data' => new GradingScaleResource($scale->load('bands'))]);
    }

    public function updateBands(GradingScaleRequest $request, int $gradingScale): JsonResponse
    {
        $scale = $this->scales->replaceBands($this->find($gradingScale), $request->validated('bands'));

        return response()->json(['success' => true, 'data' => new GradingScaleResource($scale)]);
    }

    public function makeDefault(int $gradingScale): JsonResponse
    {
        return response()->json(['success' => true, 'data' => new GradingScaleResource($this->scales->makeDefault($this->find($gradingScale)))]);
    }

    public function destroy(int $gradingScale): JsonResponse
    {
        $this->scales->delete($this->find($gradingScale));

        return response()->json(['success' => true, 'message' => 'Grading scale deleted.']);
    }

    /** Resolved by hand, not route binding: the model has no tenant scope to hide other schools' rows. */
    private function find(int $id): GradingScale
    {
        $scale = GradingScale::query()
            ->whereKey($id)
            ->where('legacy_broken', false)
            ->where(fn ($q) => $q->whereNull('school_id')->orWhere('school_id', $this->schoolId()))
            ->first();

        if ($scale === null) {
            throw (new ModelNotFoundException)->setModel(GradingScale::class, [$id]);
        }

        return $scale;
    }

    private function schoolId(): int
    {
        return $this->tenancy->currentOrFail()->getKey();
    }
}
