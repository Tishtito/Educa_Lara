<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Grading\Models\GradingScale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GradingScale */
class GradingScaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind,
            'level_id' => $this->level_id,
            'is_default' => $this->is_default,
            'is_platform' => $this->school_id === null,
            'bands' => $this->whenLoaded('bands', fn () => $this->bands->map(fn ($band) => [
                'id' => $band->id,
                'label' => $band->label,
                'abbreviation' => $band->abbreviation,
                'points' => $band->points,
                'min_marks' => (float) $band->min_marks,
                'max_marks' => (float) $band->max_marks,
                'remark' => $band->remark,
                'color' => $band->color,
                'sort_order' => $band->sort_order,
            ])->values()),
            'used_by_subjects' => $this->when(isset($this->level_subjects_count), fn () => (int) $this->level_subjects_count),
        ];
    }
}
