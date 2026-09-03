<?php

declare(strict_types=1);

namespace App\Domain\Academics\Models;

use App\Domain\People\Models\StudentEnrolment;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A class is timeless — a Grade 4 class does not move between years, students
 * do. Year-by-year membership lives in StudentEnrolment.
 *
 * Named SchoolClass because `Class` is a reserved word in PHP.
 */
class SchoolClass extends Model
{
    use BelongsToSchool;
    use HasFactory;
    use SoftDeletes;

    protected $table = 'classes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function stream(): BelongsTo
    {
        return $this->belongsTo(Stream::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(ClassAlias::class, 'class_id');
    }

    public function enrolments(): HasMany
    {
        return $this->hasMany(StudentEnrolment::class, 'class_id');
    }

    /** The level this class sits at, derived through its grade. */
    public function levelId(): ?int
    {
        return $this->grade?->level_id;
    }
}
