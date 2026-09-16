<?php

declare(strict_types=1);

namespace App\Http\Requests\Curriculum;

use App\Domain\Curriculum\Models\Subject;
use App\Domain\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // route carries role:school_admin
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => Str::upper(trim((string) $this->input('code')))]);
        }
    }

    public function rules(): array
    {
        $schoolId = app(TenantManager::class)->id();
        $subject = $this->route('subject');
        $required = $subject ? 'sometimes' : 'required';

        return [
            'code' => [$required, 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('subjects', 'code')->where('school_id', $schoolId)->ignore($subject?->id)],
            'name' => [$required, 'string', 'max:160'],
            'short_name' => ['sometimes', 'nullable', 'string', 'max:40'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** "Maths" and " maths " are the same subject (Subject::normalise backs a unique index). */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->has('name') || $validator->errors()->has('name')) {
                return;
            }

            $exists = Subject::query()
                ->withTrashed()
                ->where('name_norm', Subject::normalise((string) $this->input('name')))
                ->when($this->route('subject'), fn ($q, $subject) => $q->whereKeyNot($subject->id))
                ->exists();

            if ($exists) {
                $validator->errors()->add('name', 'There is already a subject with this name.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'There is already a subject with this code.',
            'code.regex' => 'Use letters, numbers, dashes or underscores for the code.',
        ];
    }
}
