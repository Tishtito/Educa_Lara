<?php

declare(strict_types=1);

namespace App\Domain\Billing\Mpesa;

/** Kenyan mobile numbers in the 2547XXXXXXXX / 2541XXXXXXXX form M-Pesa expects. */
final class Phone
{
    /** Null when it is not a Kenyan mobile number. */
    public static function normalise(?string $input): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $input) ?? '';

        $national = match (true) {
            str_starts_with($digits, '254') && strlen($digits) === 12 => substr($digits, 3),
            str_starts_with($digits, '0') && strlen($digits) === 10 => substr($digits, 1),
            strlen($digits) === 9 => $digits,
            default => null,
        };

        return $national !== null && preg_match('/^[17]\d{8}$/', $national) ? '254'.$national : null;
    }

    /** 2547******678, for lists and logs. */
    public static function mask(?string $phone): ?string
    {
        return $phone === null || strlen($phone) < 7 ? $phone : substr($phone, 0, 4).str_repeat('*', strlen($phone) - 7).substr($phone, -3);
    }
}
