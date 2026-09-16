<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['identity' => mb_strtolower(trim((string) $this->input('identity')))]);
    }

    /** The username or email address, as at sign-in. No school code. */
    public function rules(): array
    {
        return [
            'identity' => ['required', 'string', 'max:190'],
        ];
    }

    public function messages(): array
    {
        return ['identity.required' => 'Enter your username or email address.'];
    }
}
