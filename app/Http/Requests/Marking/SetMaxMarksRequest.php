<?php

declare(strict_types=1);

namespace App\Http\Requests\Marking;

use Illuminate\Foundation\Http\FormRequest;

class SetMaxMarksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject_paper_id' => ['required', 'integer'],
            // Omitted = every class in the exam without its own override.
            'class_id' => ['sometimes', 'nullable', 'integer'],
            'max_marks' => ['required', 'numeric', 'gt:0', 'max:9999.99', 'decimal:0,2'],
            'apply_to_existing' => ['sometimes', 'boolean'],
        ];
    }
}
