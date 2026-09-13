<?php

declare(strict_types=1);

namespace App\Domain\Examination\Services;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Examination\Enums\ExamStatus;
use App\Domain\Examination\Exceptions\InvalidExamTransition;
use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Models\ExamSubject;
use App\Domain\Examination\Models\ExamSubjectPaper;
use App\Domain\People\Models\User;
use App\Domain\Reporting\Services\ReportCardService;
use Illuminate\Support\Facades\DB;

/**
 * Moves an exam through draft -> open -> marking -> locked -> published ->
 * archived, and does what each step implies.
 *
 *   -> OPEN       the exam must cover at least one class. If no subjects were
 *                 chosen, it takes every active subject at those classes'
 *                 levels, with each paper's curriculum default as its
 *                 marks-out-of. Legacy's equivalent was implicit: an exam
 *                 covered every subject column in the table, for every class.
 *   -> LOCKED     results are computed synchronously, so a locked exam is never
 *                 left holding results from before its last marks.
 *   -> PUBLISHED  stamps published_at and freezes every student's report cards
 *                 into snapshots, in the same transaction: an exam is never
 *                 published without its issued cards on record.
 */
class ExamLifecycleService
{
    public function __construct(
        private readonly ExamComputationService $computation,
        private readonly ReportCardService $reportCards,
    ) {}

    public function transition(Exam $exam, ExamStatus $to, ?User $by = null): Exam
    {
        return DB::transaction(function () use ($exam, $to, $by) {
            $exam = Exam::query()->lockForUpdate()->findOrFail($exam->getKey());

            if (! $exam->status->canTransitionTo($to)) {
                throw InvalidExamTransition::between($exam, $to);
            }

            if ($to === ExamStatus::OPEN) {
                $this->applyDefaultConfiguration($exam);
            }

            $exam->status = $to;

            if ($to === ExamStatus::PUBLISHED) {
                $exam->published_at = now();
            }

            $exam->save();

            if ($to === ExamStatus::LOCKED) {
                $this->computation->compute($exam);
            }

            if ($to === ExamStatus::PUBLISHED) {
                $this->reportCards->snapshot($exam, $by);
            }

            return $exam->fresh(['academicYear', 'term', 'level']);
        });
    }

    /**
     * Fills in exam_subjects and exam_subject_papers for an exam that does not
     * have them yet. Leaves anything already chosen untouched, so a school that
     * configured its exam by hand keeps that configuration.
     */
    public function applyDefaultConfiguration(Exam $exam): void
    {
        $classIds = $exam->classes()->pluck('classes.id');

        if ($classIds->isEmpty()) {
            throw InvalidExamTransition::notConfigured($exam, 'it does not cover any classes yet.');
        }

        $levelIds = SchoolClass::query()
            ->with('grade')
            ->whereIn('id', $classIds)
            ->get()
            ->map(fn (SchoolClass $class) => $class->grade?->level_id)
            ->filter()
            ->unique();

        if ($exam->level_id !== null) {
            $levelIds = $levelIds->filter(fn ($id) => (int) $id === (int) $exam->level_id);
        }

        if (! ExamSubject::query()->where('exam_id', $exam->id)->exists()) {
            $levelSubjects = LevelSubject::query()
                ->whereIn('level_id', $levelIds)
                ->where('is_active', true)
                ->get();

            if ($levelSubjects->isEmpty()) {
                throw InvalidExamTransition::notConfigured($exam, 'no active subjects are set up for its classes\' levels.');
            }

            foreach ($levelSubjects as $levelSubject) {
                ExamSubject::create([
                    'school_id' => $exam->school_id,
                    'exam_id' => $exam->id,
                    'level_subject_id' => $levelSubject->id,
                    'class_id' => null,
                    'is_active' => true,
                ]);
            }
        }

        $levelSubjectIds = ExamSubject::query()->where('exam_id', $exam->id)->pluck('level_subject_id')->unique();

        $papers = LevelSubject::query()
            ->whereIn('id', $levelSubjectIds)
            ->with('papers')
            ->get()
            ->flatMap(fn (LevelSubject $ls) => $ls->papers);

        foreach ($papers as $paper) {
            // Explicit whereNull: the unique key (exam, paper, class) cannot catch
            // a duplicate all-classes row, because MySQL treats NULLs as distinct.
            $exists = ExamSubjectPaper::query()
                ->where('exam_id', $exam->id)
                ->where('subject_paper_id', $paper->id)
                ->whereNull('class_id')
                ->exists();

            if (! $exists) {
                ExamSubjectPaper::create([
                    'school_id' => $exam->school_id,
                    'exam_id' => $exam->id,
                    'subject_paper_id' => $paper->id,
                    'class_id' => null,
                    'max_marks' => $paper->default_max_marks ?? 100,
                ]);
            }
        }
    }
}
