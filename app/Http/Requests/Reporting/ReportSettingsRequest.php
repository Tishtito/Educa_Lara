<?php

declare(strict_types=1);

namespace App\Http\Requests\Reporting;

use App\Domain\Reporting\Enums\ReportTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the route carries can:manage_school_profile
    }

    public function rules(): array
    {
        return [
            'head_teacher_name' => ['present', 'nullable', 'string', 'max:160'],
            'footer' => ['present', 'nullable', 'string', 'max:300'],
            // `sometimes`, unlike the two above: a client written before
            // templates existed sends only those, and must not reset the design.
            'template' => ['sometimes', Rule::enum(ReportTemplate::class)],
        ];
    }
}
