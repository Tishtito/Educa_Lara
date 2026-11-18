<?php

declare(strict_types=1);

namespace App\Http\Requests\Resources;

use App\Domain\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** POST /teaching-materials (multipart). */
class StoreTeachingMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // route carries can:manage_teaching_materials
    }

    public function rules(): array
    {
        return ['file' => TeachingMaterialRules::file(true)] + TeachingMaterialRules::fields(true);
    }

    public function messages(): array
    {
        return ['file.max' => 'The file must be smaller than '.intdiv((int) config('elimupass.materials.max_kb', 20480), 1024).' MB.'];
    }

    public function after(): array
    {
        return [fn (Validator $validator) => TeachingMaterialRules::sameSection(
            $validator,
            $this->filled('level_id') ? $this->integer('level_id') : null,
            $this->input('grade_ids'),
            $this->filled('level_subject_id') ? $this->integer('level_subject_id') : null,
            app(TenantManager::class)->id(),
        )];
    }
}
