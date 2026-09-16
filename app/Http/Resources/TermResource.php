<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Academics\Models\Term;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Term */
class TermResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'academic_year_id' => $this->academic_year_id,
            'academic_year' => $this->whenLoaded('academicYear', fn () => $this->academicYear?->name),
            'term_number' => $this->term_number,
            'name' => $this->name,
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'feeding_fee' => $this->feeding_fee === null ? null : (float) $this->feeding_fee,
            'is_current' => $this->is_current,
        ];
    }
}
