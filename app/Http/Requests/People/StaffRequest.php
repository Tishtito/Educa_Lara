<?php

declare(strict_types=1);

namespace App\Http\Requests\People;

use App\Domain\Access\RoleService;
use App\Domain\People\Models\Role;
use App\Domain\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class StaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // route carries role:school_admin
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('username')) {
            $this->merge(['username' => strtolower(trim((string) $this->input('username')))]);
        }

        // Addresses are matched case-insensitively at sign-in, so store one case.
        if ($this->filled('email')) {
            $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
        }
    }

    public function rules(): array
    {
        $staffId = $this->route('staff');
        $required = $staffId ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:160'],
            // Unique across every school, including soft-deleted accounts (the
            // index does not know about deleted_at): people sign in with the
            // username alone, with no school code to tell two of them apart.
            'username' => [$required, 'string', 'min:3', 'max:60', 'regex:/^[a-z0-9._-]+$/',
                Rule::unique('users', 'username')->ignore($staffId)],
            // 'sometimes' would skip an absent email, so an invitation makes it required outright.
            // Unique too: an email address is an alternative way to sign in.
            'email' => array_merge(
                $this->boolean('send_invitation') ? ['required'] : ['sometimes', 'nullable'],
                ['email', 'max:190', Rule::unique('users', 'email')->ignore($staffId)],
            ),
            // Create only: email a set-up link instead of issuing a temporary password.
            'send_invitation' => [$staffId ? 'prohibited' : 'sometimes', 'boolean'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'staff_no' => ['sometimes', 'nullable', 'string', 'max:40'],
            'tsc_no' => ['sometimes', 'nullable', 'string', 'max:40'],
            'is_active' => ['sometimes', 'boolean'],
            'roles' => [$required, 'array', 'min:1'],
            'roles.*' => ['string', 'distinct', Rule::in($this->assignableRoles()->pluck('slug')->all())],
        ];
    }

    /**
     * No escalation through roles: the roles given must not reach beyond what the
     * person giving them may do (RoleService::assertAssignable).
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $slugs = $this->input('roles');

            if (! is_array($slugs) || $validator->errors()->has('roles') || $validator->errors()->has('roles.*')) {
                return;
            }

            $schoolId = app(TenantManager::class)->id();
            $roles = $this->assignableRoles()->whereIn('slug', $slugs);

            try {
                app(RoleService::class)->assertAssignable($this->user(), $roles, (int) $schoolId);
            } catch (ValidationException $e) {
                $validator->errors()->add('roles', 'You can only give roles that do no more than you can do yourself.');
            }
        }];
    }

    /** @return Collection<int, Role> */
    private function assignableRoles(): Collection
    {
        return Role::query()
            ->availableTo((int) app(TenantManager::class)->id())
            ->where('slug', '!=', Role::SUPER_ADMIN)
            ->get();
    }

    public function messages(): array
    {
        return [
            'username.unique' => 'That username is taken. Usernames are shared across all schools, so try something more specific, such as their first initial and surname.',
            'email.unique' => 'Another account already uses that email address.',
            'username.regex' => 'Use lowercase letters, numbers, dots, dashes or underscores.',
            'roles.min' => 'Give the account at least one role.',
            'email.required' => 'Enter an email address to send the invitation to.',
            'send_invitation.prohibited' => 'Send invitations for an existing account with its Send invitation action.',
        ];
    }
}
