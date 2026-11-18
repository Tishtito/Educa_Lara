<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Domain\Resources\Models\TeachingMaterial;
use App\Domain\Resources\TeachingMaterialAccess;
use App\Http\Controllers\Api\Resources\ServesMaterialFiles;
use App\Http\Controllers\Api\Resources\TeachingMaterialController;
use App\Http\Controllers\Controller;
use App\Http\Resources\TeachingMaterialResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Teaching materials in the staff portal (can:view_teaching_materials).
 * Published only; drafts and deleted materials answer 404, as if absent.
 */
class MyMaterialsController extends Controller
{
    use ServesMaterialFiles;

    public function __construct(private readonly TeachingMaterialAccess $access) {}

    /**
     * GET /my/teaching-materials?level_id=&grade_id=&level_subject_id=&q=&page=
     * With no section or grade chosen, the teacher's own grades.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $myGrades = $this->access->myGradeIds($user);
        $browsing = $request->integer('level_id') || $request->integer('grade_id');

        $page = TeachingMaterialController::filters($this->access->visibleTo($user), $request)
            ->when(! $browsing, fn (Builder $q) => $q->forGrades($myGrades))
            ->with(['level', 'grades', 'levelSubject.subject'])
            ->orderByDesc('published_at')->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page') ?: 100, 1), 200));

        return response()->json([
            'success' => true,
            'data' => TeachingMaterialResource::collection($page->getCollection())->resolve(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'my_grade_ids' => $myGrades,
            ],
        ]);
    }

    /** GET /my/teaching-materials/summary — counts, the teacher's grades, and what is new for them. */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $myGrades = $this->access->myGradeIds($user);

        return response()->json(['success' => true, 'data' => [
            'levels' => TeachingMaterialController::counts($this->access->visibleTo($user)),
            'my_grade_ids' => $myGrades,
            'new_for_my_grades' => $this->access->visibleTo($user)
                ->forGrades($myGrades)
                ->where('published_at', '>', now()->subDays(TeachingMaterialResource::NEW_FOR_DAYS))
                ->count(),
        ]]);
    }

    /** GET /my/teaching-materials/{material}/download — PDFs and images inline, the rest as attachments. */
    public function download(Request $request, TeachingMaterial $material): StreamedResponse
    {
        abort_unless($this->access->canSee($request->user(), $material), 404);

        return $this->fileResponse($material);
    }
}
