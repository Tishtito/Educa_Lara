<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Access\PermissionRegistry;
use App\Domain\Examination\Marking\MarkingAccess;
use App\Domain\Examination\Models\Exam;
use App\Domain\People\Models\User;

/**
 * Who may see a class's report cards and type onto them: a school admin, or the
 * class teacher of that class in the exam's academic year.
 *
 * Legacy protected report links only with an HMAC token in the query string
 * (ReportsController::isValidToken), so anyone holding a copied URL could open
 * any student's card. Examiners are deliberately excluded: they mark subjects,
 * they do not issue reports.
 */
class ReportAccess
{
    public function __construct(private readonly MarkingAccess $marking) {}

    public function canUseClass(User $user, Exam $exam, int $classId): bool
    {
        return $user->can(PermissionRegistry::ACCESS_ANY_CLASS)
            || in_array($classId, $this->marking->teacherClassIds($user, $exam), true);
    }
}
