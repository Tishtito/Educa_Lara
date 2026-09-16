<?php

declare(strict_types=1);

namespace App\Http\Requests\Grading;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GradingScaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // route carries role:school_admin
    }

    public function rules(): array
    {
        $creating = $this->route('gradingScale') === null;
        $bandsOnly = $this->is('api/grading-scales/*/bands');

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'kind' => ['sometimes', Rule::in(['performance_level', 'grade', 'points'])],
            'level_id' => [$creating ? 'sometimes' : 'prohibited', 'nullable', 'integer', Rule::exists('levels', 'id')],
            'is_default' => [$creating ? 'sometimes' : 'prohibited', 'boolean'],
            'bands' => [$creating || $bandsOnly ? 'required' : 'prohibited', 'array', 'min:1', 'max:20'],
            'bands.*.label' => ['required', 'string', 'max:80'],
            'bands.*.abbreviation' => ['required', 'string', 'max:16'],
            'bands.*.points' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:255'],
            'bands.*.min_marks' => ['required', 'numeric', 'min:0', 'max:100'],
            'bands.*.max_marks' => ['required', 'numeric', 'min:0', 'max:100'],
            'bands.*.remark' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bands.*.color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}
