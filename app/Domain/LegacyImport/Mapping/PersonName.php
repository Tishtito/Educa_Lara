<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport\Mapping;

/**
 * Legacy stored one free-text `name`; students now have first / middle / last.
 *
 * The split is mechanical — first token, last token, the rest in the middle — and
 * lossless: joining the parts gives back the original with whitespace collapsed,
 * which is what Student::full_name is rebuilt from. Legacy casing is kept; it is
 * the school's spelling, not the importer's to "fix".
 */
final class PersonName
{
    public static function normalise(?string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $name) ?? ''));
    }

    /** @return array{first_name: string, middle_name: string|null, last_name: string} */
    public static function split(string $name): array
    {
        $tokens = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [''];

        if (count($tokens) === 1) {
            return ['first_name' => $tokens[0], 'middle_name' => null, 'last_name' => ''];
        }

        $first = array_shift($tokens);
        $last = array_pop($tokens);

        return [
            'first_name' => $first,
            'middle_name' => $tokens === [] ? null : implode(' ', $tokens),
            'last_name' => $last,
        ];
    }
}
