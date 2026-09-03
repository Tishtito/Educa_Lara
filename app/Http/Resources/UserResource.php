<?php

declare(strict_types=1);

namespace App\Http\Resources;

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
            'is_platform_admin' => $this->school_id === null,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('slug')->all()),
            'school' => new SchoolResource($this->whenLoaded('school')),
        ];
    }
}
