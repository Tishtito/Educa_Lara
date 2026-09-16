<?php

declare(strict_types=1);

namespace App\Http\Requests\Academics;

use App\Domain\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StreamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // route carries role:school_admin
    }

    public function rules(): array
    {
        $schoolId = app(TenantManager::class)->id();
        $stream = $this->route('stream');
        $required = $stream ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:60', Rule::unique('streams', 'name')->where('school_id', $schoolId)->ignore($stream?->id)],
            'code' => ['sometimes', 'nullable', 'string', 'max:16'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    public function messages(): array
    {
        return ['name.unique' => 'There is already a stream with that name.'];
    }
}
