<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport\Mapping;

/**
 * Turns the legacy free-text class strings into a grade and stream.
 *
 * Production spells the same classes several ways, often within one database:
 * "grade_2east", "GRADE 3 EAST", "grade_3" (no stream), "7", "8_west", "9east".
 * Legacy also wrote "Completed" into the class column for pupils who had left.
 * Anything that is not recognisably a grade with an optional stream — "6_test" —
 * is not guessed at: parse() returns null and the importer quarantines it.
 */
final class ClassNameResolver
{
    private const GRADUATION_MARKERS = ['completed', 'finished', 'graduated'];

    /**
     * Stream names accepted without review. Explicit on purpose: an open pattern
     * read "6_test" as Grade 6, stream "Test". Anything else is quarantined for
     * the school to map with a class_mapping decision.
     */
    private const STREAMS = ['east', 'west', 'north', 'south', 'central',
        'blue', 'red', 'green', 'yellow', 'white', 'orange', 'purple',
        'a', 'b', 'c', 'd', 'e'];

    public function parse(?string $raw): ?ParsedClass
    {
        $text = strtolower(trim(preg_replace('/[\s_\-]+/', ' ', (string) $raw) ?? ''));

        if ($text === '') {
            return null;
        }

        if (in_array($text, self::GRADUATION_MARKERS, true)) {
            return ParsedClass::graduated();
        }

        // "grade 2east", "grade 3 east", "grade 3", "7", "8 west", "9east", "g4 blue"
        if (! preg_match('/^(?:grade|gr|g)?\s*([1-9])\s*([a-z]+)?$/', $text, $m)) {
            return null;
        }

        $stream = $m[2] ?? '';

        if ($stream !== '' && ! in_array($stream, self::STREAMS, true)) {
            return null;
        }

        $stream = $stream === '' ? null : (strlen($stream) === 1 ? strtoupper($stream) : ucfirst($stream));

        return ParsedClass::classOf((int) $m[1], $stream);
    }
}
