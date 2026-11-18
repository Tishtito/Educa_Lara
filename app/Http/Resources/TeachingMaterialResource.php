<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Resources\Models\TeachingMaterial;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never exposes the disk or path: files are fetched through the download
 * routes only.
 *
 * @mixin TeachingMaterial
 */
class TeachingMaterialResource extends JsonResource
{
    /** Published this recently, the portal marks it "New". */
    public const NEW_FOR_DAYS = 7;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'title' => $this->title,
            'description' => $this->description,
            'level_id' => $this->level_id,
            'level' => $this->whenLoaded('level', fn () => ['id' => $this->level->id, 'code' => $this->level->code, 'name' => $this->level->name]),
            'grades' => $this->whenLoaded('grades', fn () => $this->grades->map(fn ($g) => ['id' => $g->id, 'code' => $g->code, 'name' => $g->name])->values()),
            'level_subject_id' => $this->level_subject_id,
            'subject' => $this->whenLoaded('levelSubject', fn () => $this->levelSubject?->subject
                ? ['id' => $this->levelSubject->id, 'name' => $this->levelSubject->subject->name, 'code' => $this->levelSubject->subject->code]
                : null),
            'original_name' => $this->original_name,
            'extension' => $this->extension(),
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'status' => $this->status->value,
            'published_at' => $this->published_at?->toIso8601String(),
            'is_new' => $this->isPublished() && $this->published_at !== null && $this->published_at->gt(now()->subDays(self::NEW_FOR_DAYS)),
            'uploaded_by' => $this->whenLoaded('uploader', fn () => $this->uploader ? ['id' => $this->uploader->id, 'name' => $this->uploader->name] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
