<?php

declare(strict_types=1);

namespace App\Http\Requests\Academics;

use App\Domain\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAcademicYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // route carries role:school_admin
    }

    public function rules(): array
    {
        $schoolId = app(TenantManager::class)->id();
        $year = $this->route('academicYear');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:20',
                Rule::unique('academic_years', 'name')->where('school_id', $schoolId)->ignore($year?->id),
            ],
            'starts_on' => ['sometimes', 'required', 'date'],
            'ends_on' => ['sometimes', 'required', 'date', 'after:starts_on'],
        ];
    }

    public function messages(): array
    {
        return ['name.unique' => 'This school already has an academic year with that name.'];
    }
}
