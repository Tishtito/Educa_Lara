<?php

declare(strict_types=1);

namespace App\Http\Requests\Reporting;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateTermRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // route carries role:school_admin
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:40'],
            'starts_on' => ['sometimes', 'nullable', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date'],
            'feeding_fee' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
        ];
    }

    /**
     * Compared against the stored date when only one side is sent. A plain
     * after_or_equal:starts_on rule failed any request that changed ends_on
     * alone, because the rule has nothing to compare with.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $term = $this->route('term');
            $starts = $this->has('starts_on') ? $this->input('starts_on') : $term?->starts_on?->toDateString();
            $ends = $this->has('ends_on') ? $this->input('ends_on') : $term?->ends_on?->toDateString();

            if ($starts && $ends && strtotime((string) $ends) < strtotime((string) $starts)) {
                $validator->errors()->add('ends_on', 'The term cannot close before it opens.');
            }
        });
    }
}
