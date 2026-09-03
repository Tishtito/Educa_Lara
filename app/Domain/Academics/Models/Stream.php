<?php

declare(strict_types=1);

namespace App\Domain\Academics\Models;

use App\Domain\Tenancy\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * First-class stream. In every legacy deployment the stream was encoded inside
 * the free-text class name string and compared by value.
 */
class Stream extends Model
{
    use BelongsToSchool;
    use HasFactory;

    protected $guarded = ['id'];

    public function classes(): HasMany
    {
        return $this->hasMany(SchoolClass::class);
    }
}
