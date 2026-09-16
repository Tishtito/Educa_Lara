<?php

declare(strict_types=1);

namespace App\Domain\People\Services;

use App\Domain\Examination\Enums\ExamStatus;
use App\Domain\Examination\Models\ExamStudentResult;
use App\Domain\Examination\Models\ExamSubjectScore;
use App\Domain\People\Models\Student;
use Illuminate\Support\Collection;

/**
 * A pupil's final results in every exam, newest first, with the subject
 * scores behind them. Used by the admin pupil page and the class teacher's.
 * Legacy "All Results" joined on student id alone, so a pupil's rows from
 * other years bled in.
 */
class StudentResults
{
    public function for(Student $student): Collection
    {
        $final = [ExamStatus::LOCKED, ExamStatus::PUBLISHED, ExamStatus::ARCHIVED];

        $results = ExamStudentResult::query()
            ->where('student_id', $student->id)
            ->whereHas('exam', fn ($q) => $q->whereIn('status', $final))
            ->with(['exam.academicYear', 'exam.term', 'schoolClass', 'meanGradeBand'])
            ->get()
            ->sortByDesc(fn ($r) => [$r->exam?->academicYear?->name, $r->exam?->term?->term_number, $r->exam?->sequence])
            ->values();

        // The subject score is the derived row for multi-paper subjects and the
        // single entered row otherwise.
        $scores = ExamSubjectScore::query()
            ->where('student_id', $student->id)
            ->whereIn('exam_id', $results->pluck('exam_id'))
            ->with(['levelSubject.subject', 'gradeBand'])
            ->get()
            ->groupBy(fn ($s) => $s->exam_id.':'.$s->level_subject_id)
            ->map(fn ($rows) => $rows->firstWhere('is_derived', true) ?? ($rows->count() === 1 ? $rows->first() : null))
            ->filter()
            ->groupBy('exam_id');

        return $results->map(fn (ExamStudentResult $r) => [
            'exam' => [
                'id' => $r->exam_id,
                'name' => $r->exam?->name,
                'status' => $r->exam?->status?->value,
                'academic_year' => $r->exam?->academicYear?->name,
                'term' => $r->exam?->term?->name,
            ],
            'class_name' => $r->schoolClass?->name,
            'total_marks' => $r->total_marks === null ? null : (float) $r->total_marks,
            'mean_marks' => $r->mean_marks === null ? null : (float) $r->mean_marks,
            'mean_band' => $r->meanGradeBand?->abbreviation,
            'grade_position' => $r->grade_position,
            'grade_cohort_size' => $r->grade_cohort_size,
            'stream_position' => $r->stream_position,
            'stream_cohort_size' => $r->stream_cohort_size,
            'is_complete' => $r->is_complete,
            'subjects' => ($scores[$r->exam_id] ?? collect())
                ->sortBy(fn ($s) => $s->levelSubject?->display_order)
                ->map(fn (ExamSubjectScore $s) => [
                    'level_subject_id' => $s->level_subject_id,
                    'name' => $s->levelSubject?->subject?->name,
                    'code' => $s->levelSubject?->subject?->code,
                    'score' => $s->scaled_score === null ? null : (float) $s->scaled_score,
                    'band' => $s->gradeBand?->abbreviation,
                    'is_absent' => $s->is_absent,
                    'position' => $s->subject_position,
                ])->values(),
        ]);
    }
}
