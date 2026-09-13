<?php

declare(strict_types=1);

namespace App\Http\Requests\Reporting;

use App\Domain\Reporting\Enums\ReportLayout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class ReportLayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // ReportAccess is checked per class in the controller
    }

    public function rules(): array
    {
        return ['layout' => ['sometimes', new Enum(ReportLayout::class)]];
    }

    public function layout(): ReportLayout
    {
        return ReportLayout::tryFrom((string) $this->input('layout', ReportLayout::SINGLE->value)) ?? ReportLayout::SINGLE;
    }
}
