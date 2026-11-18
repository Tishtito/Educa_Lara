<?php

declare(strict_types=1);

namespace App\Domain\People\Google;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use stdClass;
use Throwable;

/**
 * Verifies the ID token an app received from Google Sign-In, on the web and in
 * the Android and iOS apps alike. Nothing from the token is trusted until:
 *
 *   - its RS256 signature checks out against Google's published keys;
 *   - it was issued by Google, to one of Elimu Pass's OAuth clients (aud);
 *   - it has not expired;
 *   - Google says the email address is verified.
 *
 * Google rotates its keys; they are cached for as long as Google's response
 * says, and fetched again once when a token names a key we have not seen.
 */
class GoogleIdTokens
{
    private const CACHE_KEY = 'google:jwks';

    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    public function __construct(private readonly Cache $cache) {}

    public function enabled(): bool
    {
        return config('services.google.client_ids') !== [];
    }

    /** @throws ValidationException when the token cannot be trusted */
    public function verify(string $idToken): GoogleIdentity
    {
        $claims = $this->decode($idToken);

        $audiences = (array) ($claims->aud ?? []);

        if (! in_array($claims->iss ?? null, self::ISSUERS, true)
            || array_intersect($audiences, config('services.google.client_ids')) === []
            || ! is_string($claims->sub ?? null)
            || ! is_string($claims->email ?? null)) {
            throw $this->invalid();
        }

        if (($claims->email_verified ?? false) !== true && ($claims->email_verified ?? null) !== 'true') {
            throw ValidationException::withMessages([
                'id_token' => ['Google has not verified the email address on this Google account.'],
            ]);
        }

        return new GoogleIdentity(
            sub: $claims->sub,
            email: mb_strtolower($claims->email),
            name: is_string($claims->name ?? null) ? $claims->name : null,
        );
    }

    private function decode(string $idToken): stdClass
    {
        $previousLeeway = JWT::$leeway;
        JWT::$leeway = 60;   // phones' clocks drift

        try {
            try {
                return JWT::decode($idToken, $this->keys());
            } catch (\UnexpectedValueException $e) {
                // A key Google started using after we cached the set.
                if (! str_contains($e->getMessage(), '"kid" invalid')) {
                    throw $e;
                }

                return JWT::decode($idToken, $this->keys(refresh: true));
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable) {
            throw $this->invalid();
        } finally {
            JWT::$leeway = $previousLeeway;
        }
    }

    /** @return array<string, Key> */
    private function keys(bool $refresh = false): array
    {
        $jwks = $refresh ? null : $this->cache->get(self::CACHE_KEY);

        if (! is_array($jwks)) {
            $response = Http::timeout(5)->retry(2, 200, throw: false)->get(config('services.google.jwks_url'));

            if (! $response->successful() || ! is_array($response->json('keys'))) {
                throw ValidationException::withMessages([
                    'id_token' => ['Google sign-in is not available right now. Try again in a minute, or sign in with your password.'],
                ]);
            }

            $jwks = $response->json();
            preg_match('/max-age=(\d+)/', (string) $response->header('Cache-Control'), $maxAge);
            $this->cache->put(self::CACHE_KEY, $jwks, max(60, (int) ($maxAge[1] ?? 3600)));
        }

        return JWK::parseKeySet($jwks, 'RS256');
    }

    private function invalid(): ValidationException
    {
        return ValidationException::withMessages([
            'id_token' => ['Google sign-in could not be completed. Try again.'],
        ]);
    }
}
