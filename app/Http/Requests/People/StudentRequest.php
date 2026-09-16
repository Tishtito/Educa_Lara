<?php

declare(strict_types=1);

namespace App\Http\Requests\People;

use App\Domain\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // route carries role:school_admin
    }

    protected function prepareForValidation(): void
    {
        foreach (['admission_no', 'first_name', 'middle_name', 'last_name', 'upi', 'guardian_name', 'guardian_phone'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim(preg_replace('/\s+/', ' ', $this->input($field)) ?? '') ?: null]);
            }
        }
    }

    public function rules(): array
    {
        $schoolId = app(TenantManager::class)->id();
        $student = $this->route('student');
        $creating = $student === null;
        $required = $creating ? 'required' : 'sometimes';

        return [
            // Including soft-deleted pupils: the unique index does not know about deleted_at.
            'admission_no' => [$required, 'string', 'max:40', Rule::unique('students', 'admission_no')->where('school_id', $schoolId)->ignore($student?->id)],
            'upi' => ['sometimes', 'nullable', 'string', 'max:40'],
            'first_name' => [$required, 'string', 'max:80'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'last_name' => [$required, 'string', 'max:80'],
            'gender' => ['sometimes', 'nullable', Rule::in(['male', 'female', 'other'])],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],
            'guardian_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'guardian_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'class_id' => [$creating ? 'required' : 'prohibited', 'integer', Rule::exists('classes', 'id')->where('school_id', $schoolId)->whereNull('deleted_at')->where('is_active', true)],
            'academic_year_id' => [$creating ? 'sometimes' : 'prohibited', 'integer', Rule::exists('academic_years', 'id')->where('school_id', $schoolId)],
            'started_on' => ['sometimes', 'nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return ['admission_no.unique' => 'Another pupil already has this admission number.'];
    }
}
