<?php

declare(strict_types=1);

namespace App\Http\Requests\Resources;

use App\Domain\Resources\Models\TeachingMaterial;
use App\Domain\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * PATCH /teaching-materials/{material}. Section, grades and subject are checked
 * together against what the material will be after the change.
 */
class UpdateTeachingMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // route carries can:manage_teaching_materials
    }

    public function rules(): array
    {
        // A title is optional on upload (the file name is used) but cannot be blanked later.
        return ['title' => ['sometimes', 'required', 'string', 'max:190']] + TeachingMaterialRules::fields(false);
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            /** @var TeachingMaterial $material */
            $material = $this->route('material');
            $levelId = $this->filled('level_id') ? $this->integer('level_id') : $material->level_id;
            $levelChanged = $levelId !== $material->level_id;

            // Grades and subject that would carry over from another section must be re-chosen.
            $gradeIds = $this->has('grade_ids') ? $this->input('grade_ids') : ($levelChanged ? [] : null);
            $subjectId = $this->has('level_subject_id')
                ? ($this->filled('level_subject_id') ? $this->integer('level_subject_id') : null)
                : ($levelChanged ? null : $material->level_subject_id);

            TeachingMaterialRules::sameSection($validator, $levelId, $gradeIds, $subjectId, app(TenantManager::class)->id());
        }];
    }

    /** Validated data, with grades cleared when the section changes and none were sent. */
    public function changes(): array
    {
        /** @var TeachingMaterial $material */
        $material = $this->route('material');
        $data = $this->validated();

        if (isset($data['level_id']) && (int) $data['level_id'] !== $material->level_id && ! array_key_exists('grade_ids', $data)) {
            $data['grade_ids'] = [];
        }

        return $data;
    }
}
