<?php

declare(strict_types=1);

namespace App\Http\Requests\Curriculum;

use App\Domain\Curriculum\Enums\AggregationRule;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class LevelSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // route carries role:school_admin
    }

    public function rules(): array
    {
        $schoolId = app(TenantManager::class)->id();
        $levelSubject = $this->route('levelSubject');
        $creating = $levelSubject === null;

        return [
            // Level and subject identify the row; they are chosen once.
            'level_id' => [$creating ? 'required' : 'prohibited', 'integer', Rule::exists('levels', 'id')],
            'subject_id' => [$creating ? 'required' : 'prohibited', 'integer', Rule::exists('subjects', 'id')->where('school_id', $schoolId)->whereNull('deleted_at')],
            'aggregation_rule' => [$creating ? 'required' : 'sometimes', new Enum(AggregationRule::class)],
            'scale_max' => ['sometimes', 'numeric', 'gt:0', 'max:9999.99'],
            'counts_toward_total' => ['sometimes', 'boolean'],
            // A school's own scale or a platform one; never another school's.
            'grading_scale_id' => ['sometimes', 'nullable', 'integer', Rule::exists('grading_scales', 'id')
                ->where(fn ($q) => $q->where('legacy_broken', false)->where(fn ($q) => $q->whereNull('school_id')->orWhere('school_id', $schoolId)))],
            'display_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
            'papers' => [$creating ? 'required' : 'sometimes', 'array', 'min:1', 'max:10'],
            'papers.*.id' => ['sometimes', 'nullable', 'integer'],
            'papers.*.name' => ['required', 'string', 'max:80'],
            'papers.*.code' => ['sometimes', 'nullable', 'string', 'max:40'],
            'papers.*.default_max_marks' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:9999.99', 'decimal:0,2'],
            'papers.*.weight' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:999.999'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $levelSubject = $this->route('levelSubject');

            if ($levelSubject === null) {
                $duplicate = LevelSubject::query()
                    ->where('level_id', $this->integer('level_id'))
                    ->where('subject_id', $this->integer('subject_id'))
                    ->exists();

                if ($duplicate) {
                    $validator->errors()->add('subject_id', 'This subject is already offered at this level.');
                }

                return;
            }

            // Paper ids must belong to this subject; anything else is a new paper
            // smuggled in under someone else's id.
            $own = $levelSubject->papers()->pluck('id')->all();
            foreach ($this->input('papers', []) as $i => $paper) {
                if (! empty($paper['id']) && ! in_array((int) $paper['id'], $own, true)) {
                    $validator->errors()->add("papers.{$i}.id", 'This paper does not belong to the subject.');
                }
            }
        });
    }
}
