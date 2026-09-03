<?php

declare(strict_types=1);

namespace App\Domain\Examination\Models;

use App\Domain\Academics\Models\Level;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Stream;
use App\Domain\Grading\Models\GradeBand;
use App\Domain\People\Models\Student;
use App\Domain\People\Models\StudentEnrolment;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One row per student per exam: the aggregate, its grade band, and positions.
 *
 * Replaces the aggregate columns of the four wide exam_results tables. It hangs
 * off enrolment_id as well as student_id, which is what stops results bleeding
 * across academic years.
 *
 * total_marks and mean_marks both exist on purpose. Legacy Upper-classes-combined
 * stored a MEAN in the column named total_marks (its SubjectController.php:449
 * divides by subjectCount) while every other deployment stored a sum, so
 * collapsing them onto one column would silently rewrite every UCC report.
 */
class ExamStudentResult extends Model
{
    use BelongsToSchool;
    use HasFactory;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'total_marks' => 'decimal:2',
            'mean_marks' => 'decimal:2',
            'legacy_total_marks' => 'decimal:2',
            'subjects_expected' => 'integer',
            'subjects_counted' => 'integer',
            'is_complete' => 'boolean',
            'computed_at' => 'datetime',
            'published_at' => 'datetime',
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

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function stream(): BelongsTo
    {
        return $this->belongsTo(Stream::class);
    }

    public function meanGradeBand(): BelongsTo
    {
        return $this->belongsTo(GradeBand::class, 'mean_grade_band_id');
    }

    /** Students with every expected subject entered. */
    public function scopeComplete(Builder $query): Builder
    {
        return $query->where('is_complete', true);
    }

    /**
     * Whether the ETL recorded a legacy aggregate that legacy:verify can compare
     * against. False for anything created in the new system.
     */
    public function hasLegacyBaseline(): bool
    {
        return $this->legacy_total_marks !== null;
    }
}
