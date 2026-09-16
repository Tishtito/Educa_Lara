<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academics;

use App\Domain\Academics\Exceptions\SetupConflict;
use App\Domain\Academics\Models\Grade;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Stream;
use App\Domain\Examination\Models\Exam;
use App\Domain\People\Models\ClassTeacherAssignment;
use App\Domain\People\Models\ExaminerAssignment;
use App\Domain\People\Models\StudentEnrolment;
use App\Domain\Tenancy\TenantManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\Academics\SchoolClassRequest;
use App\Http\Resources\SchoolClassResource;
use Illuminate\Http\JsonResponse;

/**
 * Classes: a grade plus an optional stream. Timeless — pupils move between
 * classes year to year through enrolments, the class itself stays.
 */
class SchoolClassController extends Controller
{
    public function store(SchoolClassRequest $request, TenantManager $tenancy): JsonResponse
    {
        $data = $request->validated();

        $class = SchoolClass::create([
            'school_id' => $tenancy->currentOrFail()->getKey(),
            'grade_id' => $data['grade_id'],
            'stream_id' => $data['stream_id'] ?? null,
            'name' => filled($data['name'] ?? null) ? $data['name'] : $this->defaultName($data['grade_id'], $data['stream_id'] ?? null),
            'capacity' => $data['capacity'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json(['success' => true, 'data' => new SchoolClassResource($class->load(['grade', 'stream']))], 201);
    }

    public function update(SchoolClassRequest $request, SchoolClass $schoolClass): JsonResponse
    {
        $data = $request->validated();
        $wasDefaultName = $schoolClass->name === $this->defaultName($schoolClass->grade_id, $schoolClass->stream_id);

        $schoolClass->fill(collect($data)->except('name')->all());

        if (filled($data['name'] ?? null)) {
            $schoolClass->name = $data['name'];
        } elseif ($wasDefaultName && $schoolClass->isDirty(['grade_id', 'stream_id'])) {
            // Keep a generated name in step with the grade and stream it describes.
            $schoolClass->name = $this->defaultName($schoolClass->grade_id, $schoolClass->stream_id);
        }

        $schoolClass->save();

        return response()->json(['success' => true, 'data' => new SchoolClassResource($schoolClass->load(['grade', 'stream']))]);
    }

    /**
     * Only a class nothing refers to is deleted. A class with pupils, exams or
     * teachers in its history is deactivated instead, so old mark lists and
     * report cards still name it.
     */
    public function destroy(SchoolClass $schoolClass): JsonResponse
    {
        $uses = array_filter([
            'pupil enrolments' => StudentEnrolment::query()->where('class_id', $schoolClass->id)->exists(),
            'exams' => Exam::query()->whereHas('classes', fn ($q) => $q->whereKey($schoolClass->id))->exists(),
            'class teacher assignments' => ClassTeacherAssignment::query()->where('class_id', $schoolClass->id)->exists(),
            'examiner assignments' => ExaminerAssignment::query()->where('class_id', $schoolClass->id)->exists(),
        ]);

        if ($uses !== []) {
            throw SetupConflict::cannotDelete(
                $schoolClass->name,
                'it has '.implode(', ', array_keys($uses)),
                'Mark it inactive instead; its history is kept.',
            );
        }

        $schoolClass->forceDelete();

        return response()->json(['success' => true, 'message' => 'Class deleted.']);
    }

    private function defaultName(int $gradeId, ?int $streamId): string
    {
        $grade = Grade::query()->find($gradeId)?->name ?? 'Class';
        $stream = $streamId === null ? null : Stream::query()->find($streamId)?->name;

        return trim($grade.' '.($stream ?? ''));
    }
}
