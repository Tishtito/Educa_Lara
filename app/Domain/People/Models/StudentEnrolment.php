<?php

declare(strict_types=1);

namespace App\Domain\People\Models;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * = legacy student_classes. One row per student per academic year.
 *
 * Results hang off the enrolment rather than the student, which is what stops
 * historical results leaking across years — legacy declared
 * exam_results.student_class_id NOT NULL but then read by student_id alone.
 */
class StudentEnrolment extends Model
{
    /** Read directly from a legacy student_classes row for that year. */
    public const ORIGIN_LEGACY = 'legacy_imported';

    /**
     * Deduced by the legacy import: results exist for a year with no class row
     * for it, so the class comes from the student's nearest row. Reviewable.
     */
    public const ORIGIN_INFERRED_YEAR = 'inferred_year';

    /** The kept one of several legacy class rows for the same student and year. */
    public const ORIGIN_RESOLVED_DUPLICATE = 'resolved_duplicate';

    use BelongsToSchool;
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'started_on' => 'date',
            'ended_on' => 'date',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }
}
