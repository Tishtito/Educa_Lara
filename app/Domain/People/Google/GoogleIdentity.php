<?php

declare(strict_types=1);

namespace App\Domain\People\Google;

/** The verified facts from a Google ID token that sign-in relies on. */
final readonly class GoogleIdentity
{
    public function __construct(
        /** Google's stable, never-reused id for the Google account. */
        public string $sub,
        /** Lower-cased; Google has verified the person controls it. */
        public string $email,
        public ?string $name,
    ) {}
}
