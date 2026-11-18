<?php

declare(strict_types=1);

namespace App\Domain\Timetabling\Models;

use App\Domain\Tenancy\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BellPeriod extends Model
{
    use BelongsToSchool;
    use HasFactory;

    public const LESSON = 'lesson';

    public const BREAK = 'break';

    public const LUNCH = 'lunch';

    public const KINDS = [self::LESSON, self::BREAK, self::LUNCH];

    protected $guarded = ['id'];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(BellSchedule::class, 'bell_schedule_id');
    }

    public function isLesson(): bool
    {
        return $this->kind === self::LESSON;
    }
}
