<?php

declare(strict_types=1);

namespace App\Http\Requests\Reporting;

use Illuminate\Foundation\Http\FormRequest;

class SaveReportEntriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'class_id' => ['required', 'integer'],
            'entries' => ['required', 'array', 'min:1', 'max:500'],
            'entries.*.enrolment_id' => ['required', 'integer', 'distinct'],
            // present + nullable: sending null deliberately clears the field.
            'entries.*.class_teacher_remarks' => ['present', 'nullable', 'string', 'max:1000'],
            // Negative is a credit (fees paid in advance), which schools do record.
            'entries.*.fee_balance' => ['present', 'nullable', 'numeric', 'between:-99999999.99,99999999.99', 'decimal:0,2'],
        ];
    }
}
