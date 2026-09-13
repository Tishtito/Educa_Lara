<?php

declare(strict_types=1);

namespace App\Http\Requests\Reporting;

class ClassReportRequest extends ReportLayoutRequest
{
    public function rules(): array
    {
        return parent::rules() + ['class_id' => ['required', 'integer']];
    }
}
