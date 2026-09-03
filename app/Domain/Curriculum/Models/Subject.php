<?php

declare(strict_types=1);

namespace App\Domain\Curriculum\Models;

use App\Domain\Tenancy\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A subject, owned and managed by the school. Nothing about subjects is
 * hardcoded — this replaces the legacy arrangement where subject names were
 * string-concatenated into SQL across ~8 controllers per deployment.
 */
class Subject extends Model
{
    use BelongsToSchool;
    use HasFactory;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // name_norm backs a unique index so a school cannot end up with both
        // "Maths" and "Mathematics". Maintained here rather than as a generated
        // column, so the schema carries no dependency on the MariaDB/MySQL
        // version of the host.
        static::saving(function (Subject $subject): void {
            $subject->name_norm = static::normalise($subject->name);
            $subject->code = Str::upper(trim((string) $subject->code));
        });
    }

    public static function normalise(string $name): string
    {
        return Str::lower(trim(preg_replace('/\s+/', ' ', $name) ?? $name));
    }

    public function levelSubjects(): HasMany
    {
        return $this->hasMany(LevelSubject::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(SubjectAlias::class);
    }
}
