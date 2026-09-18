<?php

declare(strict_types=1);

namespace App\Domain\People\Sessions;

use App\Domain\People\Models\PersonalAccessToken;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** What a signing-in app says about itself, recorded on the session it starts. */
final readonly class SessionDevice
{
    public function __construct(
        public string $name = 'api',
        public ?string $app = null,
        public ?string $platform = null,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
    ) {}

    /** Unknown apps and platforms are dropped rather than refused: older app builds do not send them. */
    public static function fromRequest(Request $request): self
    {
        $app = $request->input('app');
        $platform = $request->input('platform');

        return new self(
            name: Str::limit(trim((string) $request->input('device_name')) ?: 'api', 120, ''),
            app: in_array($app, PersonalAccessToken::APPS, true) ? $app : null,
            platform: in_array($platform, PersonalAccessToken::PLATFORMS, true) ? $platform : null,
            ipAddress: $request->ip(),
            userAgent: Str::limit((string) $request->userAgent(), 255, '') ?: null,
        );
    }
}
