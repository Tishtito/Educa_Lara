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

    protected function prepareForValidation(): void
    {
        $this->merge(['identity' => mb_strtolower(trim((string) $this->input('identity')))]);
    }

    /**
     * Credentials are (identity, password): the username or the email address,
     * both unique platform-wide. Legacy asked for a school as well — with one
     * database per school it had to — and the school code was carried into the
     * first version of this API. Nobody has to remember it now: the account
     * names its own school.
     */
    public function rules(): array
    {
        return [
            'identity' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return ['identity.required' => 'Enter your username or email address.'];
    }
}
