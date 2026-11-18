<?php

declare(strict_types=1);

namespace App\Domain\Resources\Models;

use App\Domain\Academics\Models\Grade;
use App\Domain\Academics\Models\Level;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\People\Models\User;
use App\Domain\Resources\Enums\MaterialStatus;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A file a school shares with its teachers. One section (level); grades are
 * optional, and none means the whole section. The file lives on a private
 * disk and is only ever served through an authenticated, tenant-scoped route.
 */
class TeachingMaterial extends Model
{
    use BelongsToSchool;
    use HasFactory;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => MaterialStatus::class,
            'published_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function levelSubject(): BelongsTo
    {
        return $this->belongsTo(LevelSubject::class);
    }

    public function grades(): BelongsToMany
    {
        return $this->belongsToMany(Grade::class, 'teaching_material_grades')->orderBy('grades.ordinal');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isPublished(): bool
    {
        return $this->status === MaterialStatus::Published;
    }

    /** Lower-case extension of the stored file, from its path. */
    public function extension(): string
    {
        return strtolower(pathinfo($this->path, PATHINFO_EXTENSION));
    }

    /**
     * Materials for any of these grades: tagged with one of them, or untagged
     * (whole section) in one of their sections.
     *
     * @param  list<int>  $gradeIds
     */
    public function scopeForGrades(Builder $query, array $gradeIds): Builder
    {
        if ($gradeIds === []) {
            return $query->whereRaw('1 = 0');
        }

        $levelIds = Grade::query()->whereIn('id', $gradeIds)->distinct()->pluck('level_id')->all();

        return $query->where(fn (Builder $q) => $q
            ->whereHas('grades', fn (Builder $g) => $g->whereIn('grades.id', $gradeIds))
            ->orWhere(fn (Builder $q) => $q->whereIn('level_id', $levelIds)->whereDoesntHave('grades')));
    }
}
