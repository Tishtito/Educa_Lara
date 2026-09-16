<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Curriculum\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Subject */
class SubjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'short_name' => $this->short_name,
            'is_active' => $this->is_active,
            'level_ids' => $this->whenLoaded('levelSubjects', fn () => $this->levelSubjects->pluck('level_id')->unique()->values()),
        ];
    }
}
