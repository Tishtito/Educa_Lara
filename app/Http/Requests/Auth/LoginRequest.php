<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `school` is optional but usually required in practice: users is unique on
     * (school_key, username), not on username alone, so two schools may each have
     * a "jkamau". It may be omitted when the request arrives on a hostname that
     * identifies the school, or for a platform superadmin.
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:60'],
            'password' => ['required', 'string'],
            'school' => ['sometimes', 'nullable', 'string', 'max:190'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }
}
