<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Access\Access;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'phone' => $this->phone,
            'staff_no' => $this->staff_no,
            'tsc_no' => $this->tsc_no,
            'is_active' => $this->is_active,
            'must_change_password' => $this->must_change_password,
            'last_login_at' => $this->last_login_at,
            'google_connected' => $this->google_sub !== null,
            'is_platform_admin' => $this->school_id === null,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('slug')->all()),
            'role_names' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')->all()),
            // What this person may do in their school (or, for a superadmin, the
            // school they are acting in). The apps show and hide by these; the API
            // enforces them regardless.
            'permissions' => app(Access::class)->permissionsFor($this->resource),
            'school' => new SchoolResource($this->whenLoaded('school')),
        ];
    }
}
