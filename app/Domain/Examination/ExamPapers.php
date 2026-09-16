<?php

declare(strict_types=1);

namespace App\Domain\Examination;

use App\Domain\Curriculum\Models\SubjectPaper;
use App\Domain\Examination\Models\Exam;
use App\Domain\Examination\Models\ExamSubjectPaper;
use Illuminate\Support\Collection;

/**
 * Which papers a subject has *in one exam*.
 *
 * The curriculum (subject_papers) is a catalogue of the papers a subject can
 * have; an exam names the ones it actually sets, through its
 * exam_subject_papers rows. That is what lets a Mid-Term be one paper where the
 * End-Term is two, without inventing a second subject — which is what schools
 * had to do before, because marking, computation and report cards all read the
 * curriculum list directly.
 *
 * An exam with no rows for a subject falls back to the whole catalogue: exams
 * created before papers could be chosen, and any exam not configured by hand,
 * behave exactly as they always did.
 *
 * Everything that asks "what are this exam's papers" comes through here, so the
 * answer cannot drift between the mark sheet, the computation and the cards.
 */
class ExamPapers
{
    /** @return Collection<int, Collection<int, SubjectPaper>> level_subject_id => papers, in sequence */
    public function forExam(Exam $exam, ?iterable $levelSubjectIds = null): Collection
    {
        $catalogue = SubjectPaper::query()
            ->when($levelSubjectIds !== null, fn ($q) => $q->whereIn('level_subject_id', $levelSubjectIds))
            ->orderBy('sequence')
            ->get()
            ->groupBy(fn (SubjectPaper $paper) => (int) $paper->level_subject_id);

        $chosen = ExamSubjectPaper::query()
            ->where('exam_id', $exam->id)
            ->whereNull('class_id')
            ->pluck('subject_paper_id')
            ->map(fn ($id) => (int) $id)
            ->flip();

        return $catalogue->map(function (Collection $papers) use ($chosen) {
            $selected = $papers->filter(fn (SubjectPaper $paper) => $chosen->has((int) $paper->id))->values();

            // No row for any of this subject's papers: the exam never chose, so
            // it sets them all.
            return $selected->isEmpty() ? $papers->values() : $selected;
        });
    }

    /** @return Collection<int, SubjectPaper> */
    public function forSubject(Exam $exam, int $levelSubjectId): Collection
    {
        return $this->forExam($exam, [$levelSubjectId])->get($levelSubjectId) ?? collect();
    }

    /** Whether this exam sets this paper at all. */
    public function includes(Exam $exam, SubjectPaper $paper): bool
    {
        return $this->forSubject($exam, (int) $paper->level_subject_id)
            ->contains(fn (SubjectPaper $inExam) => (int) $inExam->id === (int) $paper->id);
    }
}
