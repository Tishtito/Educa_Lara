<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Examination\Enums\ExamStatus;
use App\Domain\Examination\Models\Exam;
use App\Http\Controllers\Controller;
use App\Http\Requests\Exam\StoreExamRequest;
use App\Http\Resources\ExamResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Replaces the exam listing scattered across the legacy admin, class-teacher and
 * examiner portals (ExamsController, ExamController x2), which each ran their own
 * near-identical query against a per-deployment database.
 *
 * Every query here is tenant-scoped by TenantScope, because Exam uses
 * BelongsToSchool.
 */
class ExamController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $exams = Exam::query()
            ->with(['academicYear', 'term', 'level'])
            ->when($request->integer('academic_year_id'), fn ($q, $v) => $q->where('academic_year_id', $v))
            ->when($request->integer('term_id'), fn ($q, $v) => $q->where('term_id', $v))
            ->when($request->integer('level_id'), fn ($q, $v) => $q->where('level_id', $v))
            ->when(
                $request->string('status')->toString(),
                fn ($q, $v) => $q->where('status', $v),
                // Archived exams are historical (ExamStatus::ARCHIVED): hidden from
                // the default listing, reachable by asking for them.
                fn ($q) => $request->boolean('include_archived') ? $q : $q->where('status', '!=', ExamStatus::ARCHIVED),
            )
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            // Capped: an uncapped per_page lets one request load every exam and
            // its relations.
            ->paginate(min(max($request->integer('per_page') ?: 25, 1), 100));

        return response()->json([
            'success' => true,
            'data' => ExamResource::collection($exams)->resolve(),
            'meta' => [
                'current_page' => $exams->currentPage(),
                'last_page' => $exams->lastPage(),
                'per_page' => $exams->perPage(),
                'total' => $exams->total(),
            ],
        ]);
    }

    public function show(Exam $exam): JsonResponse
    {
        $exam->load(['academicYear', 'term', 'level', 'classes']);

        return response()->json([
            'success' => true,
            'data' => new ExamResource($exam),
        ]);
    }

    public function store(StoreExamRequest $request): JsonResponse
    {
        $data = $request->validated();
        $classIds = $data['class_ids'] ?? [];
        unset($data['class_ids']);

        // school_id is stamped by BelongsToSchool from the resolved tenant, and
        // status starts at draft: an exam is not markable until it is configured.
        $exam = Exam::create($data + [
            'status' => ExamStatus::DRAFT,
            'created_by' => $request->user()->id,
        ]);

        if ($classIds !== []) {
            // The pivot carries school_id of its own, so stamp it explicitly —
            // attach() bypasses model events.
            $schoolId = $exam->school_id;
            $exam->classes()->attach(
                collect($classIds)->mapWithKeys(fn (int $id) => [$id => ['school_id' => $schoolId]])->all()
            );
        }

        $exam->load(['academicYear', 'term', 'level', 'classes']);

        return response()->json([
            'success' => true,
            'data' => new ExamResource($exam),
        ], 201);
    }
}
