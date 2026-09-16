<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport;

use RuntimeException;

/** @internal Thrown inside the import transaction to roll it back on purpose. */
final class RollbackImport extends RuntimeException {}
