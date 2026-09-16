<?php

declare(strict_types=1);

namespace App\Http\Requests\Exam;

use App\Domain\Academics\Models\Term;
use App\Domain\Examination\Enums\ExamType;
use App\Domain\Examination\Enums\MeanPolicy;
use App\Domain\Examination\Enums\TiePolicy;
use App\Domain\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

/** Which fields may change in which state is decided by ExamSetupService, not here. */
class UpdateExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // route carries role:school_admin
    }

    public function rules(): array
    {
        $schoolId = app(TenantManager::class)->id();

        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'exam_type' => ['sometimes', 'required', new Enum(ExamType::class)],
            'term_id' => ['sometimes', 'nullable', 'integer', Rule::exists('terms', 'id')->where('school_id', $schoolId)],
            'level_id' => ['sometimes', 'nullable', 'integer', Rule::exists('levels', 'id')],
            'sequence' => ['sometimes', 'required', 'integer', 'min:0', 'max:255'],
            'starts_on' => ['sometimes', 'nullable', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date'],
            'mean_policy' => ['sometimes', 'required', new Enum(MeanPolicy::class)],
            'tie_policy' => ['sometimes', 'required', new Enum(TiePolicy::class)],
            'class_ids' => ['sometimes', 'array'],
            'class_ids.*' => ['integer', 'distinct', Rule::exists('classes', 'id')->where('school_id', $schoolId)->whereNull('deleted_at')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $exam = $this->route('exam');

            if ($this->input('tie_policy') === TiePolicy::LEGACY_ORDINAL->value && ! $exam?->isLegacyImport()) {
                $validator->errors()->add('tie_policy', 'The legacy ordinal tie policy is reserved for exams imported from the old system.');
            }

            // A term from another year would put the exam's report cards in the wrong term.
            if ($this->filled('term_id') && $exam) {
                $termYear = Term::query()->whereKey($this->integer('term_id'))->value('academic_year_id');
                if ($termYear !== null && (int) $termYear !== (int) $exam->academic_year_id) {
                    $validator->errors()->add('term_id', 'That term belongs to a different academic year.');
                }
            }

            $starts = $this->has('starts_on') ? $this->input('starts_on') : $exam?->starts_on?->toDateString();
            $ends = $this->has('ends_on') ? $this->input('ends_on') : $exam?->ends_on?->toDateString();
            if ($starts && $ends && strtotime((string) $ends) < strtotime((string) $starts)) {
                $validator->errors()->add('ends_on', 'The exam cannot end before it starts.');
            }
        });
    }
}
