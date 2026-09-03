<?php

declare(strict_types=1);

namespace App\Domain\Grading\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradeBand extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'min_marks' => 'decimal:2',
            'max_marks' => 'decimal:2',
            'points' => 'integer',
        ];
    }

    public function gradingScale(): BelongsTo
    {
        return $this->belongsTo(GradingScale::class);
    }

    public function contains(float $mark): bool
    {
        return $mark >= (float) $this->min_marks && $mark <= (float) $this->max_marks;
    }
}
