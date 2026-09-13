<?php

declare(strict_types=1);

namespace App\Domain\Examination\Data;

/** What a computation run did, for the API response and the console. */
final class ComputationReport
{
    public int $students = 0;

    public int $subjectScores = 0;

    public int $absentSubjects = 0;

    /** Subjects skipped because at least one paper has no mark yet. */
    public int $pendingSubjects = 0;

    public int $completeStudents = 0;

    public int $statistics = 0;

    public int $removedResults = 0;

    /** @var list<string> */
    public array $warnings = [];

    public function toArray(): array
    {
        return [
            'students' => $this->students,
            'complete_students' => $this->completeStudents,
            'subject_scores' => $this->subjectScores,
            'absent_subjects' => $this->absentSubjects,
            'pending_subjects' => $this->pendingSubjects,
            'statistics' => $this->statistics,
            'removed_results' => $this->removedResults,
            'warnings' => $this->warnings,
        ];
    }
}
