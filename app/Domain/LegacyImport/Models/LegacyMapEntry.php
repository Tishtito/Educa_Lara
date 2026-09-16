<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport\Models;

use App\Domain\Tenancy\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * One legacy row → one target row (legacy_migration_map). The checksum is of the
 * source row as last imported, so a re-run can tell unchanged rows from edited ones.
 */
class LegacyMapEntry extends Model
{
    use BelongsToSchool;

    protected $table = 'legacy_migration_map';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['migrated_at' => 'datetime'];
    }
}
