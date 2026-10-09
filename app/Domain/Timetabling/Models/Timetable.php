<?php

declare(strict_types=1);

namespace App\Domain\Timetabling\Models;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\Term;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use App\Domain\Timetabling\Enums\TimetableStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Timetable extends Model
{
    use BelongsToSchool;
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => TimetableStatus::class,
            'seed' => 'integer',
            'stats' => 'array',
            'diagnostics' => 'array',
            'started_at' => 'datetime',
            'generated_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_timetable_id');
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(TimetableLesson::class);
    }
}
