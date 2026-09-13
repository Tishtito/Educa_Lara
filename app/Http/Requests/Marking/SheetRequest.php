<?php

declare(strict_types=1);

namespace App\Http\Requests\Marking;

use Illuminate\Foundation\Http\FormRequest;

/** Identifies a mark sheet. Existence and tenancy are checked by MarksheetService::resolve(). */
class SheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // MarkingAccess is checked in the controller, per sheet
    }

    public function rules(): array
    {
        return [
            'class_id' => ['required', 'integer'],
            'level_subject_id' => ['required', 'integer'],
        ];
    }
}
