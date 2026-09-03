<?php

declare(strict_types=1);

namespace App\Domain\Examination\Models;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which subjects an exam covers, chosen at creation time.
 *
 * Legacy had no equivalent: an exam implicitly covered every subject column in
 * exam_results, so a Grade 1 pupil carried NULL columns for subjects their level
 * never sat, and the "is this student complete?" check could not tell a missing
 * mark from a subject that did not apply.
 *
 * class_id NULL means every class in the exam, which is the common case.
 */
class ExamSubject extends Model
{
    use BelongsToSchool;
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function levelSubject(): BelongsTo
    {
        return $this->belongsTo(LevelSubject::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /** True when this row applies to every class in the exam. */
    public function appliesToAllClasses(): bool
    {
        return $this->class_id === null;
    }
}
