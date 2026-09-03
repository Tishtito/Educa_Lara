<?php

declare(strict_types=1);

namespace App\Domain\Academics\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Platform reference data — deliberately NOT tenant-scoped. Levels are the same
 * for every Kenyan school, and keeping them global is what allows one curriculum
 * service to serve every tenant.
 */
class Level extends Model
{
    use HasFactory;

    public const LOWER_PRIMARY = 'lower_primary';

    public const UPPER_PRIMARY = 'upper_primary';

    public const JUNIOR_SECONDARY = 'junior_secondary';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'min_grade' => 'integer',
            'max_grade' => 'integer',
        ];
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class)->orderBy('sort_order');
    }
}
