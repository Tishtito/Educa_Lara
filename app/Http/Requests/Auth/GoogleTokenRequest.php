<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/** A Google ID token from Continue with Google, plus the usual device details. */
class GoogleTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_token' => ['required', 'string', 'max:4096'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }
}
