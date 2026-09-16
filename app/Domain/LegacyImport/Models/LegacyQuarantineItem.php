<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport\Models;

use App\Domain\LegacyImport\Quarantine;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/** A legacy row or cell the importer would not guess about (legacy_quarantine). */
class LegacyQuarantineItem extends Model
{
    use BelongsToSchool;

    protected $table = 'legacy_quarantine';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'resolved_at' => 'datetime'];
    }

    public function isBlocking(): bool
    {
        return in_array($this->reason, Quarantine::BLOCKING, true);
    }
}
