<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport\Profiles;

/** A subject in the school's catalogue, shared by every section that teaches it. */
final readonly class SubjectSpec
{
    public function __construct(
        public string $code,
        public string $name,
        public int $displayOrder,
    ) {}
}
