<?php

declare(strict_types=1);

namespace App\Http\Requests\Academics;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SchoolClassRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // route carries role:school_admin
    }

    public function rules(): array
    {
        $schoolId = app(TenantManager::class)->id();
        $required = $this->route('schoolClass') ? 'sometimes' : 'required';

        return [
            // grades are platform reference data: no school_id constraint.
            'grade_id' => [$required, 'integer', Rule::exists('grades', 'id')],
            'stream_id' => ['sometimes', 'nullable', 'integer', Rule::exists('streams', 'id')->where('school_id', $schoolId)],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * One class per grade and stream. The unique index covers it for streamed
     * classes, but MySQL treats NULLs as distinct, so two stream-less "Grade 1"
     * classes would slip through without this check.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $current = $this->route('schoolClass');
            $gradeId = $this->has('grade_id') ? $this->integer('grade_id') : $current?->grade_id;
            $streamId = $this->has('stream_id') ? ($this->input('stream_id') === null ? null : $this->integer('stream_id')) : $current?->stream_id;

            $duplicate = SchoolClass::query()
                ->where('grade_id', $gradeId)
                ->when($streamId === null, fn ($q) => $q->whereNull('stream_id'), fn ($q) => $q->where('stream_id', $streamId))
                ->when($current, fn ($q) => $q->whereKeyNot($current->id))
                ->withTrashed()
                ->exists();

            if ($duplicate) {
                $validator->errors()->add('stream_id', 'This grade already has a class for that stream.');
            }
        });
    }
}
