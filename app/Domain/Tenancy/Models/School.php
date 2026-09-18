<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

use App\Domain\Billing\Models\SchoolSubscription;
use App\Domain\Billing\Subscriptions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * The tenant root. Not itself tenant-scoped.
 */
class School extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'legacy_source_systems' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (School $school): void {
            $school->uuid ??= (string) Str::uuid();
            $school->slug ??= Str::slug($school->name);
        });

        // Every school starts on a free trial (docs/billing.md).
        static::created(fn (School $school) => app(Subscriptions::class)->startTrial($school));
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(SchoolSubscription::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(SchoolDomain::class);
    }

    public function settings(): HasMany
    {
        return $this->hasMany(SchoolSetting::class);
    }

    /**
     * A school setting, or the given default.
     *
     * This is where report-card identity (school name, logo, address) lives —
     * replacing the legacy arrangement where the name was hardcoded into report
     * templates, and disagreed between the on-screen card and the PDF.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings->firstWhere('key', $key)?->value ?? $default;
    }
}
