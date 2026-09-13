<?php

declare(strict_types=1);

namespace App\Http\Requests\Marking;

use Illuminate\Foundation\Http\FormRequest;

class SelectExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Existence is checked through Eloquent in the controller, where
        // TenantScope applies; Rule::exists would not be tenant-scoped.
        return ['exam_id' => ['required', 'integer']];
    }
}
