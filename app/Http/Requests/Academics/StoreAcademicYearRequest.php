<?php

declare(strict_types=1);

namespace App\Http\Requests\Academics;

use App\Domain\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAcademicYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // route carries role:school_admin
    }

    public function rules(): array
    {
        $schoolId = app(TenantManager::class)->id();

        return [
            'name' => ['required', 'string', 'max:20', Rule::unique('academic_years', 'name')->where('school_id', $schoolId)],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
            'make_current' => ['sometimes', 'boolean'],
            'terms' => ['sometimes', 'array', 'min:1', 'max:4'],
            'terms.*.term_number' => ['required', 'integer', 'min:1', 'max:4', 'distinct'],
            'terms.*.name' => ['required', 'string', 'max:40'],
            'terms.*.starts_on' => ['nullable', 'date'],
            'terms.*.ends_on' => ['nullable', 'date'],
            'terms.*.feeding_fee' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ($this->input('terms', []) as $i => $term) {
                if (! empty($term['starts_on']) && ! empty($term['ends_on']) && $term['ends_on'] < $term['starts_on']) {
                    $validator->errors()->add("terms.{$i}.ends_on", 'A term cannot end before it starts.');
                }
            }
        });
    }

    public function messages(): array
    {
        return ['name.unique' => 'This school already has an academic year with that name.'];
    }
}
