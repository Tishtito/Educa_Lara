<?php

declare(strict_types=1);

namespace App\Http\Requests\Exam;

use App\Domain\Examination\Enums\ExamStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class TransitionExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // route carries role:school_admin
    }

    public function rules(): array
    {
        return ['status' => ['required', new Enum(ExamStatus::class)]];
    }

    public function status(): ExamStatus
    {
        return ExamStatus::from($this->validated('status'));
    }
}
