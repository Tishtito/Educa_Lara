<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Setup\ExamSetupService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Exam\UpdateExamRequest;
use App\Http\Resources\ExamResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Changing an exam after creation: details, classes, subjects, and deleting drafts. */
class ExamSetupController extends Controller
{
    public function __construct(private readonly ExamSetupService $setup) {}

    public function update(UpdateExamRequest $request, Exam $exam): JsonResponse
    {
        return response()->json(['success' => true, 'data' => new ExamResource($this->setup->update($exam, $request->validated()))]);
    }

    public function destroy(Exam $exam): JsonResponse
    {
        $this->setup->delete($exam);

        return response()->json(['success' => true, 'message' => 'Exam deleted.']);
    }

    public function show(Exam $exam): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->setup->describe($exam)]);
    }

    /**
     * The exam's subjects and, per subject, the papers it sets with their
     * marks-out-of. `level_subject_ids` is the older shorthand for "every paper
     * of these subjects".
     */
    public function updateSubjects(Request $request, Exam $exam): JsonResponse
    {
        $data = $request->validate([
            'subjects' => ['sometimes', 'array', 'max:50'],
            'subjects.*.level_subject_id' => ['required', 'integer', 'distinct'],
            'subjects.*.papers' => ['sometimes', 'array', 'max:10'],
            'subjects.*.papers.*.subject_paper_id' => ['required', 'integer', 'distinct'],
            'subjects.*.papers.*.max_marks' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:9999.99'],
            'level_subject_ids' => ['sometimes', 'array', 'max:50'],
            'level_subject_ids.*' => ['integer', 'distinct'],
        ]);

        if (! array_key_exists('subjects', $data) && ! array_key_exists('level_subject_ids', $data)) {
            throw ValidationException::withMessages(['subjects' => 'Choose the subjects this exam examines.']);
        }

        if (array_key_exists('subjects', $data)) {
            $this->setup->configureSubjects($exam, $data['subjects']);
        } else {
            $this->setup->chooseSubjects($exam, array_map('intval', $data['level_subject_ids']));
        }

        return response()->json(['success' => true, 'data' => $this->setup->describe($exam)]);
    }
}
