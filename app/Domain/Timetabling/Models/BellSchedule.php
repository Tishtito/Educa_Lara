<?php

declare(strict_types=1);

namespace App\Domain\Timetabling\Models;

use App\Domain\Academics\Models\Level;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The shape of a school day for one level (level_id NULL: the school's default
 * for levels without their own). Replaces the CBC generator's single
 * periods-per-day number and its clock times hardcoded in JavaScript.
 */
class BellSchedule extends Model
{
    use BelongsToSchool;
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'days' => 'array',
        ];
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function periods(): HasMany
    {
        return $this->hasMany(BellPeriod::class)->orderBy('sequence');
    }

    /** @return list<int> ISO weekdays, ascending */
    public function dayNumbers(): array
    {
        $days = array_map('intval', $this->days ?? []);
        sort($days);

        return array_values(array_unique($days));
    }
}
