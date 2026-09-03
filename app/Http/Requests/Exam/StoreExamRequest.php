<?php

declare(strict_types=1);

namespace App\Http\Requests\Exam;

use App\Domain\Examination\Enums\ExamType;
use App\Domain\Examination\Enums\MeanPolicy;
use App\Domain\Examination\Enums\TiePolicy;
use App\Domain\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // route carries auth:sanctum + role:school_admin
    }

    public function rules(): array
    {
        // Rule::exists goes through the query builder, NOT Eloquent, so
        // TenantScope does not apply to it. Every exists rule against a
        // tenant-owned table must therefore carry school_id itself — otherwise a
        // school admin could attach their exam to another school's academic year
        // and the FK would happily accept it.
        $schoolId = app(TenantManager::class)->id();

        return [
            'academic_year_id' => [
                'required', 'integer',
                Rule::exists('academic_years', 'id')->where('school_id', $schoolId),
            ],
            'term_id' => [
                'nullable', 'integer',
                Rule::exists('terms', 'id')->where('school_id', $schoolId),
            ],
            // levels is platform reference data, shared by every tenant, so no
            // school_id constraint applies here.
            'level_id' => ['nullable', 'integer', Rule::exists('levels', 'id')],

            'name' => ['required', 'string', 'max:120'],
            'exam_type' => ['required', new Enum(ExamType::class)],
            'sequence' => ['nullable', 'integer', 'min:0', 'max:255'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'mean_policy' => ['nullable', new Enum(MeanPolicy::class)],
            // legacy_ordinal is rejected in withValidator(): it exists only so
            // imported exams reproduce legacy's tie-free ranking, and must never
            // be chosen for a new exam.
            'tie_policy' => ['nullable', new Enum(TiePolicy::class)],

            'class_ids' => ['sometimes', 'array'],
            'class_ids.*' => [
                'integer',
                Rule::exists('classes', 'id')
                    ->where('school_id', $schoolId)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->input('tie_policy') === TiePolicy::LEGACY_ORDINAL->value) {
                $validator->errors()->add(
                    'tie_policy',
                    'The legacy ordinal tie policy is reserved for exams imported from the old system.'
                );
            }
        });
    }
}
