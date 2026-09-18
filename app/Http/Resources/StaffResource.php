<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\People\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @mixin User */
class StaffResource extends JsonResource
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
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            // Most recent use of any session, when the controller loads it.
            'last_active_at' => $this->when(array_key_exists('last_active_at', $this->getAttributes()), fn () => $this->last_active_at ? Carbon::parse($this->last_active_at)->toIso8601String() : null),
            'google_connected' => $this->google_sub !== null,
            // An unused, uncancelled invitation. Null once they have set up the account.
            'invitation' => $this->whenLoaded('pendingInvitation', fn () => $this->pendingInvitation ? [
                'status' => $this->pendingInvitation->isExpired() ? 'expired' : 'pending',
                'sent_to' => $this->pendingInvitation->sent_to,
                'sent_at' => $this->pendingInvitation->created_at?->toIso8601String(),
                'expires_at' => $this->pendingInvitation->expires_at->toIso8601String(),
            ] : null),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('slug')->values()),
            'role_names' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')->values()),
            'permissions' => $this->when($this->getAttribute('permission_slugs') !== null, fn () => $this->getAttribute('permission_slugs')),
            // Current-year duties, when the controller loads them.
            'class_teacher_of' => $this->whenLoaded('classTeacherAssignments', fn () => $this->classTeacherAssignments
                ->map(fn ($a) => ['class_id' => $a->class_id, 'class_name' => $a->schoolClass?->name])->values()),
            'examiner_assignments_count' => $this->when(isset($this->examiner_assignments_count), fn () => (int) $this->examiner_assignments_count),
        ];
    }
}
