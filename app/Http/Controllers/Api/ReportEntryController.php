<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Examination\Models\Exam;
use App\Domain\People\Models\StudentEnrolment;
use App\Domain\Reporting\Exceptions\ReportCardUnavailable;
use App\Domain\Reporting\Models\ReportCardEntry;
use App\Domain\Reporting\ReportAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reporting\SaveReportEntriesRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What class teachers type onto their students' cards: remarks and fee balance.
 * Legacy printed both as blank lines to be filled in by hand.
 *
 *   GET /api/exams/{exam}/report-entries?class_id=
 *   PUT /api/exams/{exam}/report-entries
 *
 * Editable until the exam is published; publishing freezes them into the
 * issued cards.
 */
class ReportEntryController extends Controller
{
    public function __construct(private readonly ReportAccess $access) {}

    public function index(Request $request, Exam $exam): JsonResponse
    {
        $request->validate(['class_id' => ['required', 'integer']]);
        $classId = $this->classInExam($exam, $request->integer('class_id'));

        if (! $this->access->canUseClass($request->user(), $exam, $classId)) {
            return $this->forbidden();
        }

        $entries = ReportCardEntry::query()->where('exam_id', $exam->id)->get()->keyBy('enrolment_id');

        $students = StudentEnrolment::query()
            ->where('academic_year_id', $exam->academic_year_id)
            ->where('class_id', $classId)
            ->where(fn (Builder $q) => $q->where('status', 'active')->orWhereIn('id', $entries->keys()->all() ?: [0]))
            ->whereHas('student')
            ->with('student')
            ->get()
            ->sortBy(fn (StudentEnrolment $e) => $e->student->full_name)
            ->map(fn (StudentEnrolment $e) => [
                'enrolment_id' => $e->id,
                'student_id' => $e->student_id,
                'admission_no' => $e->student->admission_no,
                'name' => $e->student->full_name,
                'class_teacher_remarks' => $entries->get($e->id)?->class_teacher_remarks,
                'fee_balance' => $entries->get($e->id)?->fee_balance === null ? null : (float) $entries->get($e->id)->fee_balance,
                'updated_at' => $entries->get($e->id)?->updated_at?->toIso8601String(),
            ])
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'editable' => $exam->status->allowsReportEntries(),
                'students' => $students,
            ],
        ]);
    }

    public function update(SaveReportEntriesRequest $request, Exam $exam): JsonResponse
    {
        $classId = $this->classInExam($exam, $request->integer('class_id'));

        if (! $this->access->canUseClass($request->user(), $exam, $classId)) {
            return $this->forbidden();
        }

        $rows = $request->validated('entries');

        $saved = DB::transaction(function () use ($exam, $classId, $rows, $request) {
            $exam = Exam::query()->lockForUpdate()->findOrFail($exam->id);

            if (! $exam->status->allowsReportEntries()) {
                throw ReportCardUnavailable::entriesFrozen($exam);
            }

            $enrolments = StudentEnrolment::query()
                ->where('academic_year_id', $exam->academic_year_id)
                ->where('class_id', $classId)
                ->whereIn('id', array_column($rows, 'enrolment_id'))
                ->get()
                ->keyBy('id');

            $errors = [];

            foreach ($rows as $i => $row) {
                if (! $enrolments->has((int) $row['enrolment_id'])) {
                    $errors["entries.{$i}.enrolment_id"][] = 'This student is not enrolled in this class for the exam\'s academic year.';
                }
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $now = now();

            ReportCardEntry::query()->upsert(
                array_map(fn (array $row) => [
                    'school_id' => $exam->school_id,
                    'exam_id' => $exam->id,
                    'enrolment_id' => (int) $row['enrolment_id'],
                    'student_id' => $enrolments[(int) $row['enrolment_id']]->student_id,
                    'class_teacher_remarks' => filled($row['class_teacher_remarks']) ? trim((string) $row['class_teacher_remarks']) : null,
                    'fee_balance' => $row['fee_balance'] === null ? null : round((float) $row['fee_balance'], 2),
                    'updated_by' => $request->user()->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $rows),
                ['exam_id', 'enrolment_id'],
                ['class_teacher_remarks', 'fee_balance', 'updated_by', 'updated_at'],
            );

            return count($rows);
        });

        return response()->json(['success' => true, 'data' => ['saved' => $saved]]);
    }

    private function classInExam(Exam $exam, int $classId): int
    {
        if (! $exam->classes()->where('classes.id', $classId)->exists()) {
            throw ValidationException::withMessages(['class_id' => ['This class is not part of the exam.']]);
        }

        return $classId;
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'You can only fill in report cards for classes you teach.'], 403);
    }
}
