<?php

declare(strict_types=1);

namespace App\Domain\Examination\Models;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Curriculum\Models\SubjectPaper;
use App\Domain\Grading\Models\GradeBand;
use App\Domain\People\Models\Student;
use App\Domain\People\Models\StudentEnrolment;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The narrow replacement for the per-subject columns of exam_results.
 *
 * Two kinds of row live here, distinguished by subject_paper_id:
 *
 *   entered   subject_paper_id NOT NULL, is_derived = false. One per paper the
 *             examiner actually marks.
 *   derived   subject_paper_id NULL, is_derived = true. The rolled-up subject
 *             score produced by AggregationService from the entered rows,
 *             following the LevelSubject's aggregation_rule.
 *
 * A single-paper subject still gets both, so every read path — marklist, report
 * card, statistics — can select derived rows only and never needs to know how
 * many papers a subject has. That uniformity is what removed the four-way fork
 * in the legacy marklist and report controllers.
 *
 * max_marks is a snapshot of the effective max at entry time, not a lookup.
 * Legacy recomputed aggregates against the CURRENT marks_out_of, so an admin
 * correcting a typo in it retroactively changed percentages on report cards
 * already issued.
 */
class ExamSubjectScore extends Model
{
    use BelongsToSchool;
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'raw_score' => 'decimal:2',
            'max_marks' => 'decimal:2',
            'scaled_score' => 'decimal:2',
            'is_derived' => 'boolean',
            'is_absent' => 'boolean',
            'entered_at' => 'datetime',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrolment::class, 'enrolment_id');
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function levelSubject(): BelongsTo
    {
        return $this->belongsTo(LevelSubject::class);
    }

    public function subjectPaper(): BelongsTo
    {
        return $this->belongsTo(SubjectPaper::class);
    }

    public function gradeBand(): BelongsTo
    {
        return $this->belongsTo(GradeBand::class);
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    /** The rolled-up subject rows. Every read path that shows subjects wants these. */
    public function scopeDerived(Builder $query): Builder
    {
        return $query->where('is_derived', true)->whereNull('subject_paper_id');
    }

    /** The rows an examiner actually enters, one per paper. */
    public function scopeEntered(Builder $query): Builder
    {
        return $query->where('is_derived', false)->whereNotNull('subject_paper_id');
    }
}
