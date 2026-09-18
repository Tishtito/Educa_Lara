<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Billing\Subscriptions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SchoolResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // uuid, never id: a school is addressed publicly by uuid or slug so
            // that the numeric key stays non-enumerable.
            'uuid' => $this->uuid,
            'name' => $this->name,
            'short_name' => $this->short_name,
            'slug' => $this->slug,
            'motto' => $this->motto,
            'logo_path' => $this->logo_path,
            'timezone' => $this->timezone,
            'status' => $this->status,
            // Whether exams, marking, mark lists and report cards are open (docs/billing.md).
            'subscription' => app(Subscriptions::class)->stateFor($this->resource),
        ];
    }
}
