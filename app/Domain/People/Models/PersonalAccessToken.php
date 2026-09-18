<?php

declare(strict_types=1);

namespace App\Domain\People\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * A signed-in session. Beyond Sanctum's columns it records which app, platform
 * and sign-in method started it, so people can recognise their devices on the
 * Account page and sign out the ones they do not.
 */
class PersonalAccessToken extends SanctumToken
{
    public const APPS = ['admin', 'portal'];

    public const PLATFORMS = ['web', 'android', 'ios'];

    public const PASSWORD = 'password';

    public const GOOGLE = 'google';

    public const INVITATION = 'invitation';

    public const RESET = 'reset';

    protected $table = 'personal_access_tokens';
}
