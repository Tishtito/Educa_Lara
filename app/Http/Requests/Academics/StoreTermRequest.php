<?php

declare(strict_types=1);

namespace App\Http\Requests\Academics;

use App\Domain\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTermRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // route carries role:school_admin
    }

    public function rules(): array
    {
        $schoolId = app(TenantManager::class)->id();

        return [
            'academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')->where('school_id', $schoolId)],
            'term_number' => [
                'required', 'integer', 'min:1', 'max:4',
                Rule::unique('terms', 'term_number')->where('academic_year_id', $this->integer('academic_year_id')),
            ],
            'name' => ['required', 'string', 'max:40'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'feeding_fee' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
        ];
    }

    public function messages(): array
    {
        return ['term_number.unique' => 'That year already has a term with this number.'];
    }
}
