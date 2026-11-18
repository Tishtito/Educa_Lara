<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Resources;

use App\Domain\Academics\Models\Level;
use App\Domain\Resources\Enums\MaterialStatus;
use App\Domain\Resources\Models\TeachingMaterial;
use App\Domain\Resources\Services\TeachingMaterialService;
use App\Domain\Tenancy\TenantManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\Resources\StoreTeachingMaterialRequest;
use App\Http\Requests\Resources\TeachingMaterialRules;
use App\Http\Requests\Resources\UpdateTeachingMaterialRequest;
use App\Http\Resources\TeachingMaterialResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Teaching materials in the admin app (can:manage_teaching_materials):
 * upload, tag, publish, replace, delete and restore. Teachers read them
 * through Portal\MyMaterialsController.
 */
class TeachingMaterialController extends Controller
{
    use ServesMaterialFiles;

    public function __construct(
        private readonly TeachingMaterialService $materials,
        private readonly TenantManager $tenancy,
    ) {}

    /** GET /teaching-materials?level_id=&grade_id=&level_subject_id=&status=&q=&deleted=1&page=&per_page= */
    public function index(Request $request): JsonResponse
    {
        $page = $this->filtered(TeachingMaterial::query(), $request)
            ->when($request->boolean('deleted'), fn (Builder $q) => $q->onlyTrashed())
            ->when($request->string('status')->toString(), fn (Builder $q, string $status) => $q->where('status', $status))
            ->with(['level', 'grades', 'levelSubject.subject', 'uploader'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page') ?: 50, 1), 200));

        return response()->json([
            'success' => true,
            'data' => TeachingMaterialResource::collection($page->getCollection())->resolve(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** GET /teaching-materials/summary — counts per section and grade, for the tab badges. */
    public function summary(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['levels' => self::counts(TeachingMaterial::query(), withDrafts: true)]]);
    }

    /** POST /teaching-materials (multipart) */
    public function store(StoreTeachingMaterialRequest $request): JsonResponse
    {
        $data = $request->validated();
        $material = $this->materials->store($this->tenancy->currentOrFail(), $request->user(), $request->file('file'), $data);

        return response()->json([
            'success' => true,
            'data' => $this->present($material),
            'duplicate_of' => $this->duplicate($material),
        ], 201);
    }

    /** PATCH /teaching-materials/{material} */
    public function update(UpdateTeachingMaterialRequest $request, TeachingMaterial $material): JsonResponse
    {
        $material = $this->materials->update($material, $request->user(), $request->changes());

        return response()->json(['success' => true, 'data' => $this->present($material)]);
    }

    /** POST /teaching-materials/{material}/file — a new file for the same record. */
    public function replaceFile(Request $request, TeachingMaterial $material): JsonResponse
    {
        $request->validate(['file' => TeachingMaterialRules::file(true)]);
        $material = $this->materials->replaceFile($material, $request->user(), $request->file('file'));

        return response()->json([
            'success' => true,
            'data' => $this->present($material),
            'duplicate_of' => $this->duplicate($material),
        ]);
    }

    /** DELETE /teaching-materials/{material} — soft; restorable until materials:prune. */
    public function destroy(Request $request, TeachingMaterial $material): JsonResponse
    {
        $this->materials->delete($material, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Material deleted. It can be restored for '.config('elimupass.materials.retention_days').' days.',
        ]);
    }

    /** POST /teaching-materials/{material}/restore (binds deleted materials too) */
    public function restore(Request $request, TeachingMaterial $material): JsonResponse
    {
        if (! $material->trashed()) {
            return response()->json(['success' => true, 'data' => $this->present($material)]);
        }

        return response()->json(['success' => true, 'data' => $this->present($this->materials->restore($material, $request->user()))]);
    }

    /** GET /teaching-materials/{material}/download — drafts too, for the admin's preview. */
    public function download(TeachingMaterial $material): StreamedResponse
    {
        return $this->fileResponse($material);
    }

    /** Filters shared with the portal list. */
    public static function filters(Builder $query, Request $request): Builder
    {
        $search = $request->string('q')->trim()->toString();

        return $query
            ->when($request->integer('level_id'), fn (Builder $q, int $id) => $q->where('level_id', $id))
            ->when($request->integer('grade_id'), fn (Builder $q, int $id) => $q->forGrades([$id]))
            ->when($request->integer('level_subject_id'), fn (Builder $q, int $id) => $q->where('level_subject_id', $id))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('title', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('original_name', 'like', '%'.addcslashes($search, '%_\\').'%')));
    }

    /**
     * Per section: its materials, and per grade those a teacher of that grade
     * would see (tagged with it, or for the whole section).
     *
     * @return list<array<string, mixed>>
     */
    public static function counts(Builder $base, bool $withDrafts = false): array
    {
        return Level::query()->with('grades')->orderBy('sort_order')->get()->map(fn (Level $level) => [
            'id' => $level->id,
            'code' => $level->code,
            'name' => $level->name,
            'count' => (clone $base)->where('level_id', $level->id)->count(),
            'drafts' => $withDrafts ? (clone $base)->where('level_id', $level->id)->where('status', MaterialStatus::Draft)->count() : null,
            'grades' => $level->grades->sortBy('ordinal')->values()->map(fn ($grade) => [
                'id' => $grade->id,
                'code' => $grade->code,
                'name' => $grade->name,
                'count' => (clone $base)->forGrades([$grade->id])->count(),
            ])->all(),
        ])->all();
    }

    private function filtered(Builder $query, Request $request): Builder
    {
        return self::filters($query, $request);
    }

    private function present(TeachingMaterial $material): TeachingMaterialResource
    {
        return new TeachingMaterialResource($material->load(['level', 'grades', 'levelSubject.subject', 'uploader']));
    }

    /** @return array{id: int, title: string}|null */
    private function duplicate(TeachingMaterial $material): ?array
    {
        $other = $this->materials->duplicateOf($material);

        return $other ? ['id' => $other->id, 'title' => $other->title] : null;
    }
}
