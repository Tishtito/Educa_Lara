<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Curriculum\Models\LevelSubject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LevelSubject */
class LevelSubjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'level_id' => $this->level_id,
            'subject_id' => $this->subject_id,
            'subject' => $this->whenLoaded('subject', fn () => [
                'id' => $this->subject->id,
                'code' => $this->subject->code,
                'name' => $this->subject->name,
            ]),
            'paper_count' => $this->paper_count,
            'aggregation_rule' => $this->aggregation_rule?->value,
            'scale_max' => (float) $this->scale_max,
            'counts_toward_total' => $this->counts_toward_total,
            'grading_scale_id' => $this->grading_scale_id,
            'display_order' => $this->display_order,
            'is_active' => $this->is_active,
            'papers' => $this->whenLoaded('papers', fn () => $this->papers->map(fn ($paper) => [
                'id' => $paper->id,
                'sequence' => $paper->sequence,
                'name' => $paper->name,
                'code' => $paper->code,
                'default_max_marks' => $paper->default_max_marks === null ? null : (float) $paper->default_max_marks,
                'weight' => (float) $paper->weight,
            ])->values()),
            // Set by the controller: structure is frozen once an exam beyond
            // draft uses this subject.
            'in_use' => $this->when(isset($this->in_use), fn () => (bool) $this->in_use),
        ];
    }
}
