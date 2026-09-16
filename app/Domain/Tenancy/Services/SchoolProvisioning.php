<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Services;

use App\Domain\Academics\Services\AcademicCalendar;
use App\Domain\Curriculum\DefaultCurriculum;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\User;
use App\Domain\People\Services\StaffService;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;

/**
 * A new school on the platform: the school, its first administrator (invited
 * by email, or given a temporary password), the current academic year and the
 * default CBC subjects for each level (DefaultCurriculum). Legacy onboarding was a
 * copy of four codebases and three databases per school.
 */
class SchoolProvisioning
{
    public function __construct(
        private readonly StaffService $staff,
        private readonly AcademicCalendar $calendar,
        private readonly TenantManager $tenancy,
        private readonly DefaultCurriculum $defaults,
    ) {}

    /**
     * @param  array<string, mixed>  $school
     * @param  array{name: string, username: string, email?: ?string, phone?: ?string, send_invitation?: bool}  $admin
     * @return array{school: School, admin_username: string, temporary_password: ?string, invitation_sent_to: ?string}
     */
    public function provision(array $school, array $admin, ?User $by = null): array
    {
        return DB::transaction(function () use ($school, $admin, $by) {
            $created = School::create([
                'name' => $school['name'],
                'short_name' => $school['short_name'] ?? null,
                'slug' => $school['slug'],
                'county' => $school['county'] ?? null,
                'phone' => $school['phone'] ?? null,
                'email' => $school['email'] ?? null,
                'timezone' => 'Africa/Nairobi',
                'status' => $school['status'] ?? 'onboarding',
            ]);

            // Tenant-owned rows are written as the new school.
            return $this->tenancy->asSchool($created, function () use ($created, $admin, $by) {
                $invite = ! empty($admin['send_invitation']) && ! empty($admin['email']);
                [$user, $password] = $this->staff->create($created->id, $admin, [Role::SCHOOL_ADMIN], invitedBy: $by, invite: $invite);

                $year = (string) now()->year;
                $this->calendar->createYear($created->id, [
                    'name' => $year,
                    'starts_on' => "{$year}-01-01",
                    'ends_on' => "{$year}-12-31",
                    'make_current' => true,
                ], []);

                // A school can set its first exam without building a curriculum.
                $this->defaults->apply($created);

                return [
                    'school' => $created,
                    'admin_username' => $user->username,
                    'temporary_password' => $password,
                    'invitation_sent_to' => $invite ? $user->email : null,
                ];
            });
        });
    }
}
