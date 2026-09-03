<?php

declare(strict_types=1);

namespace App\Domain\People\Models;

use App\Domain\Tenancy\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use BelongsToSchool;
    use HasFactory;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
        ];
    }

    protected static function booted(): void
    {
        // Maintained here rather than as a generated column, keeping the schema
        // free of any dependency on the host's MariaDB/MySQL version.
        static::saving(function (Student $student): void {
            $student->full_name = trim(implode(' ', array_filter([
                $student->first_name,
                $student->middle_name,
                $student->last_name,
            ])));
        });
    }

    public function enrolments(): HasMany
    {
        return $this->hasMany(StudentEnrolment::class);
    }

    /** Enrolment for a given academic year, which is what pins results to a class. */
    public function enrolmentFor(int $academicYearId): ?StudentEnrolment
    {
        return $this->enrolments()
            ->where('academic_year_id', $academicYearId)
            ->first();
    }
}
