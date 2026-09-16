<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport\Models;

use App\Domain\Tenancy\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/** A question about ambiguous legacy data, answered once by the school (legacy_decisions). */
class LegacyDecision extends Model
{
    use BelongsToSchool;

    public const STUDENT_MATCH = 'student_match';

    public const STAFF_CONFLICT = 'staff_conflict';

    public const CLASS_MAPPING = 'class_mapping';

    public const ENROLMENT_DUPLICATE = 'enrolment_duplicate';

    public const PENDING = 'pending';

    public const ACCEPT = 'accept';

    public const REJECT = 'reject';

    public const CUSTOM = 'custom';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['proposal' => 'array', 'payload' => 'array', 'decided_at' => 'datetime'];
    }

    public function isPending(): bool
    {
        return $this->decision === self::PENDING;
    }
}
