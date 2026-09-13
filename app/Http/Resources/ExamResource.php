<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'exam_type' => $this->exam_type?->value,
            'exam_type_label' => $this->exam_type?->label(),
            'sequence' => $this->sequence,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'mean_policy' => $this->mean_policy?->value,
            'tie_policy' => $this->tie_policy?->value,
            // Surfaced so a client can explain why an exam's positions look odd:
            // imported exams keep legacy's tie-free ranking on purpose.
            'is_legacy_import' => $this->isLegacyImport(),
            'accepts_score_entry' => $this->acceptsScoreEntry(),
            'results_up_to_date' => ! $this->resultsAreStale(),
            'results_computed_at' => $this->results_computed_at?->toIso8601String(),
            'published_at' => $this->published_at,
            'academic_year' => $this->whenLoaded('academicYear', fn () => [
                'id' => $this->academicYear->id,
                'name' => $this->academicYear->name,
            ]),
            'term' => $this->whenLoaded('term', fn () => $this->term ? [
                'id' => $this->term->id,
                'name' => $this->term->name,
                'term_number' => $this->term->term_number,
            ] : null),
            'level' => $this->whenLoaded('level', fn () => $this->level ? [
                'id' => $this->level->id,
                'code' => $this->level->code,
                'name' => $this->level->name,
            ] : null),
        ];
    }
}
