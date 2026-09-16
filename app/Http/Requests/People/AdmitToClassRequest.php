<?php

declare(strict_types=1);

namespace App\Http\Requests\People;

/**
 * Admitting a pupil from the staff portal: the same pupil rules as the admin
 * app, but the class comes from the route and the year is always the current
 * one.
 */
class AdmitToClassRequest extends StudentRequest
{
    public function rules(): array
    {
        return collect(parent::rules())->except(['class_id', 'academic_year_id'])->all();
    }
}
