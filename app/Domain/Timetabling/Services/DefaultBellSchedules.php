<?php

declare(strict_types=1);

namespace App\Domain\Timetabling\Services;

use App\Domain\Academics\Models\Level;
use App\Domain\Timetabling\Models\BellPeriod;

/**
 * A starting school day per level, built on the CBC lesson lengths (lower
 * primary 30 minutes, upper primary 35, junior secondary 40). Offered once so a
 * school does not start from an empty screen; every period is editable after.
 * The CBC generator hardcoded one day for every level (40-minute slots in
 * shared-pages.js), which is wrong for both primary levels.
 */
final class DefaultBellSchedules
{
    /** @var array<string, array{lesson: int, after_lunch: int}> */
    private const LEVELS = [
        Level::LOWER_PRIMARY => ['lesson' => 30, 'after_lunch' => 1],
        Level::UPPER_PRIMARY => ['lesson' => 35, 'after_lunch' => 2],
        Level::JUNIOR_SECONDARY => ['lesson' => 40, 'after_lunch' => 3],
    ];

    /**
     * Two lessons, short break, two lessons, long break, two lessons, lunch,
     * then the level's afternoon lessons, from 08:00.
     *
     * @return list<array{kind: string, label: ?string, starts_at: string, ends_at: string}>
     */
    public static function periodsFor(string $levelCode): array
    {
        $level = self::LEVELS[$levelCode] ?? self::LEVELS[Level::UPPER_PRIMARY];
        $blocks = [
            [BellPeriod::LESSON, 2], [BellPeriod::BREAK, 'Short break', 10],
            [BellPeriod::LESSON, 2], [BellPeriod::BREAK, 'Long break', 30],
            [BellPeriod::LESSON, 2], [BellPeriod::LUNCH, 'Lunch', 60],
            [BellPeriod::LESSON, $level['after_lunch']],
        ];

        $clock = 8 * 60;
        $periods = [];

        foreach ($blocks as $block) {
            if ($block[0] === BellPeriod::LESSON) {
                for ($i = 0; $i < $block[1]; $i++) {
                    $periods[] = self::period(BellPeriod::LESSON, null, $clock, $level['lesson']);
                    $clock += $level['lesson'];
                }

                continue;
            }

            $periods[] = self::period($block[0], $block[1], $clock, $block[2]);
            $clock += $block[2];
        }

        return $periods;
    }

    private static function period(string $kind, ?string $label, int $start, int $minutes): array
    {
        $time = fn (int $m) => sprintf('%02d:%02d', intdiv($m, 60), $m % 60);

        return ['kind' => $kind, 'label' => $label, 'starts_at' => $time($start), 'ends_at' => $time($start + $minutes)];
    }
}
