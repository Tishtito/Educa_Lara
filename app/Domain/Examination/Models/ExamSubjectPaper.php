<?php

declare(strict_types=1);

namespace App\Domain\Examination\Models;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Curriculum\Models\SubjectPaper;
use App\Domain\Grading\Models\GradingScale;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * = legacy marks_out_of, but per paper rather than per subject.
 *
 * The old table keyed on (exam_id, subject) and so could not express an uneven
 * split — Lugha out of 60 and Insha out of 40 both had to be recorded against
 * "Kiswahili". JSS worked around this by hardcoding 50/50 in the controller.
 *
 * Note that a score's max is snapshotted onto exam_subject_scores.max_marks when
 * it is entered. This row is the source for that snapshot, not a substitute for
 * it: editing the value here must not retroactively change historical
 * percentages, which is exactly what legacy did.
 */
class ExamSubjectPaper extends Model
{
    use BelongsToSchool;
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'max_marks' => 'decimal:2',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function subjectPaper(): BelongsTo
    {
        return $this->belongsTo(SubjectPaper::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function gradingScale(): BelongsTo
    {
        return $this->belongsTo(GradingScale::class);
    }
}
