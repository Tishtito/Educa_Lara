<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Resources\Models;

use App\Domain\Academics\Models\Level;
use App\Domain\Resources\Enums\MaterialStatus;
use App\Domain\Resources\Models\TeachingMaterial;
use App\Domain\Tenancy\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Rows only: tests that download a file put one on the faked disk at `path`.
 *
 * @extends Factory<TeachingMaterial>
 */
class TeachingMaterialFactory extends Factory
{
    protected $model = TeachingMaterial::class;

    public function definition(): array
    {
        $uuid = (string) Str::uuid();

        return [
            'uuid' => $uuid,
            'school_id' => School::factory(),
            'level_id' => fn () => Level::query()->where('code', Level::UPPER_PRIMARY)->value('id'),
            'title' => 'Scheme of work',
            'disk' => 'local',
            'path' => "materials-test/{$uuid}.pdf",
            'original_name' => 'scheme.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'sha256' => hash('sha256', $uuid),
            'status' => MaterialStatus::Published,
            'published_at' => now(),
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => MaterialStatus::Draft, 'published_at' => null]);
    }
}
