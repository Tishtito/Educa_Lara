<?php

declare(strict_types=1);

namespace App\Http\Requests\Reporting;

use Illuminate\Foundation\Http\FormRequest;

class ReportSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // route carries role:school_admin
    }

    public function rules(): array
    {
        return [
            'head_teacher_name' => ['present', 'nullable', 'string', 'max:160'],
            'footer' => ['present', 'nullable', 'string', 'max:300'],
        ];
    }
}
