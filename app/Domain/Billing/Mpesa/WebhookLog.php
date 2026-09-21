<?php

declare(strict_types=1);

namespace App\Domain\Billing\Mpesa;

use Illuminate\Http\Request;

/**
 * What to record about a webhook call, for the log.
 *
 * M-Pesa callbacks carry people's phone numbers and names, and the URL carries
 * the webhook secret, so neither reaches the log intact: long digit runs are
 * masked and the secret is replaced.
 */
final class WebhookLog
{
    private const SNIPPET_LENGTH = 200;

    /** @return array<string, mixed> */
    public static function context(Request $request): array
    {
        return [
            'path' => preg_replace('#/pay/[^/]+/#', '/pay/<secret>/', $request->path()),
            'ip' => $request->ip(),
            'content_type' => $request->header('Content-Type'),
            'bytes' => strlen($request->getContent()),
            'body' => self::redact($request->getContent()),
        ];
    }

    /** The start of a body with phone numbers, receipts and amounts' digits masked. */
    public static function redact(string $body): string
    {
        $snippet = mb_strimwidth(trim($body), 0, self::SNIPPET_LENGTH, '…');

        // Any run of 6+ digits is a number that identifies somebody: keep its shape only.
        return (string) preg_replace_callback('/\d{6,}/', fn (array $m) => substr($m[0], 0, 3).str_repeat('*', strlen($m[0]) - 3), $snippet);
    }
}
