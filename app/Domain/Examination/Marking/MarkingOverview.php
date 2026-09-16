<?php

declare(strict_types=1);

namespace App\Domain\Examination\Marking;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Curriculum\Models\SubjectPaper;
use App\Domain\Examination\ExamPapers;
use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Models\ExamSubject;
use App\Domain\Examination\Models\ExamSubjectScore;
use App\Domain\People\Models\StudentEnrolment;
use App\Domain\People\Models\User;

/**
 * The marksheets one user may open in an exam, with progress. Shared by the
 * marking tab (every sheet) and the staff portal's exam list (a progress
 * summary), so "how far is marking" means the same thing in both places.
 */
class MarkingOverview
{
    public function __construct(
        private readonly MarkingAccess $access,
        private readonly ExamPapers $papers,
    ) {}

    /**
     * @return list<array{class_id: int, class_name: string, level_subject_id: int, subject_name: ?string, subject_code: ?string, papers: list<array{subject_paper_id: int, name: string}>, can_enter: bool, students: int, marks_entered: int, marks_expected: int, is_complete: bool}>
     */
    public function sheets(User $user, Exam $exam): array
    {
        $enterable = $this->access->enterablePairs($user, $exam);   // null = admin, all
        $teacherOf = $this->access->teacherClassIds($user, $exam);

        $classes = $exam->classes()->with('grade')->get()->keyBy('id');
        $examPapers = $this->papers->forExam($exam);
        $examSubjects = ExamSubject::query()
            ->where('exam_id', $exam->id)
            ->where('is_active', true)
            ->with(['levelSubject.subject', 'levelSubject.papers'])
            ->get();

        $enteredCounts = ExamSubjectScore::query()
            ->where('exam_id', $exam->id)
            ->entered()
            ->selectRaw('class_id, level_subject_id, COUNT(*) as entered')
            ->groupBy('class_id', 'level_subject_id')
            ->get()
            ->keyBy(fn ($row) => $row->class_id.':'.$row->level_subject_id);

        $rosterSizes = StudentEnrolment::query()
            ->where('academic_year_id', $exam->academic_year_id)
            ->whereIn('class_id', $classes->keys())
            ->where('status', 'active')
            ->selectRaw('class_id, COUNT(*) as students')
            ->groupBy('class_id')
            ->pluck('students', 'class_id');

        $sheets = [];

        foreach ($classes as $class) {
            /** @var SchoolClass $class */
            foreach ($examSubjects as $examSubject) {
                $levelSubject = $examSubject->levelSubject;

                if ($levelSubject === null
                    || ($examSubject->class_id !== null && (int) $examSubject->class_id !== (int) $class->id)
                    || (int) $levelSubject->level_id !== (int) $class->grade?->level_id) {
                    continue;
                }

                $key = $class->id.':'.$levelSubject->id;

                if (isset($sheets[$key])) {
                    continue;
                }

                $canEnter = $enterable === null || in_array(
                    ['class_id' => (int) $class->id, 'level_subject_id' => (int) $levelSubject->id],
                    $enterable,
                    true,
                );
                $canView = $canEnter || in_array((int) $class->id, $teacherOf, true);

                if (! $canView) {
                    continue;
                }

                $students = (int) ($rosterSizes[$class->id] ?? 0);
                $subjectPapers = $examPapers->get($levelSubject->id) ?? collect();
                $papers = $subjectPapers->count();
                $entered = (int) ($enteredCounts->get($key)?->entered ?? 0);

                $sheets[$key] = [
                    'class_id' => $class->id,
                    'class_name' => $class->name,
                    'level_subject_id' => $levelSubject->id,
                    'subject_name' => $levelSubject->subject?->name,
                    'subject_code' => $levelSubject->subject?->code,
                    'papers' => $subjectPapers->map(fn (SubjectPaper $p) => ['subject_paper_id' => $p->id, 'name' => $p->name])->values()->all(),
                    'can_enter' => $canEnter,
                    'students' => $students,
                    'marks_entered' => $entered,
                    'marks_expected' => $students * $papers,
                    'is_complete' => $students > 0 && $entered >= $students * $papers,
                ];
            }
        }

        return array_values($sheets);
    }

    /** @return array{sheets: int, complete_sheets: int, marks_entered: int, marks_expected: int} */
    public function progress(User $user, Exam $exam, bool $enterableOnly = false): array
    {
        $sheets = collect($this->sheets($user, $exam))->when($enterableOnly, fn ($c) => $c->where('can_enter', true));

        return [
            'sheets' => $sheets->count(),
            'complete_sheets' => $sheets->where('is_complete', true)->count(),
            'marks_entered' => (int) $sheets->sum('marks_entered'),
            'marks_expected' => (int) $sheets->sum('marks_expected'),
        ];
    }
}
