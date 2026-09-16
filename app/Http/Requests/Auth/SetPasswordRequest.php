<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/** Choosing a password from an invitation or reset link. Same rule as ChangePasswordRequest. */
class SetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }
}
