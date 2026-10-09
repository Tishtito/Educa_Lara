<?php

declare(strict_types=1);

namespace App\Domain\Timetabling\Models;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\Grade;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lessons per week of one subject for every class of a grade, in one year. */
class LessonRequirement extends Model
{
    use BelongsToSchool;
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'lessons_per_week' => 'integer',
            'max_per_day' => 'integer',
            'double_lessons' => 'integer',
        ];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function levelSubject(): BelongsTo
    {
        return $this->belongsTo(LevelSubject::class);
    }
}
