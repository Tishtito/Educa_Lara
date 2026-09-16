<?php

declare(strict_types=1);

namespace App\Domain\People\Exceptions;

use App\Domain\People\Models\AccountToken;
use DomainException;

/**
 * An invitation or password-reset link that cannot be used. Rendered as 410
 * with a reason the sign-in screens turn into a next step.
 */
class AccountLinkUnavailable extends DomainException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function invalid(string $purpose): self
    {
        return new self('invalid', $purpose === AccountToken::INVITATION
            ? 'This invitation link is not valid. It may have been replaced by a newer one — check for a later email, or ask your school administrator to send it again.'
            : 'This password reset link is not valid. It may have been replaced by a newer one — request a new link.');
    }

    public static function expired(string $purpose): self
    {
        return new self('expired', $purpose === AccountToken::INVITATION
            ? 'This invitation has expired. Ask your school administrator to send a new one.'
            : 'This password reset link has expired. Request a new one.');
    }

    public static function used(string $purpose): self
    {
        return new self('used', $purpose === AccountToken::INVITATION
            ? 'This invitation has already been used. Sign in with your username and the password you chose.'
            : 'This password reset link has already been used. Sign in, or request a new link.');
    }

    public static function accountUnavailable(): self
    {
        return new self('unavailable', 'This account can no longer be used. Contact your school administrator.');
    }
}
