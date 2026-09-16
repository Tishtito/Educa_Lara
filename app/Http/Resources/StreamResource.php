<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Academics\Models\Stream;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Stream */
class StreamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'sort_order' => $this->sort_order,
            'classes_count' => $this->whenCounted('classes'),
        ];
    }
}
