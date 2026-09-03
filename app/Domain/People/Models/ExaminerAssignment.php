<?php

declare(strict_types=1);

namespace App\Domain\People\Models;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The unified (examiner, class, subject, year) grant. Four incompatible legacy
 * models collapse onto this one shape — see the migration for the mapping.
 */
class ExaminerAssignment extends Model
{
    use BelongsToSchool;
    use HasFactory;

    /**
     * Set on rows the ETL inferred from an examiners.class_assigned string
     * rather than an explicit grant, so an admin can review and prune them.
     * Without this, lower-classes and UCC examiners silently gain the right to
     * mark every subject.
     */
    public const ORIGIN_EXPANDED = 'expanded_from_class_assigned';

    protected $guarded = ['id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function levelSubject(): BelongsTo
    {
        return $this->belongsTo(LevelSubject::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function wasInferred(): bool
    {
        return $this->origin === self::ORIGIN_EXPANDED;
    }
}
