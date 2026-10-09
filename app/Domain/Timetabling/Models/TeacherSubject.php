<?php

declare(strict_types=1);

namespace App\Domain\Timetabling\Models;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A subject (at one level) a teacher can teach in a year. The timetable
 * generator gives each class's subject to one of the teachers who ticked it,
 * unless a TeachingAssignment pins the class to someone.
 */
class TeacherSubject extends Model
{
    use BelongsToSchool;
    use HasFactory;

    protected $guarded = ['id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function levelSubject(): BelongsTo
    {
        return $this->belongsTo(LevelSubject::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
