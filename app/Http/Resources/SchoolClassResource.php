<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Academics\Models\SchoolClass;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SchoolClass */
class SchoolClassResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'grade_id' => $this->grade_id,
            'grade' => $this->whenLoaded('grade', fn () => $this->grade?->name),
            'level_id' => $this->whenLoaded('grade', fn () => $this->grade?->level_id),
            'stream_id' => $this->stream_id,
            'stream' => $this->whenLoaded('stream', fn () => $this->stream?->name),
            'capacity' => $this->capacity,
            'is_active' => $this->is_active,
            // Present only where the controller counts them (the class list for
            // the current year), so the lookup list stays a cheap query.
            'students_count' => $this->when(isset($this->students_count), fn () => (int) $this->students_count),
        ];
    }
}
