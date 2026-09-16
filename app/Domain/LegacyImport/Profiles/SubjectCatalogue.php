<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport\Profiles;

/**
 * The school's subject catalogue, shared across sections so that "Mathematics"
 * is one subject taught at three levels, not three subjects.
 *
 * Names follow the legacy report cards' labels (ReportsController::subjects()),
 * which is what teachers and parents already know. A school can rename them.
 */
final class SubjectCatalogue
{
    public const MATHS = 'MAT';

    public const ENGLISH = 'ENG';

    public const KISWAHILI = 'KIS';

    public const READING = 'REA';

    public const KUSOMA = 'KSM';

    public const ENVIRONMENTAL = 'ENV';

    public const CREATIVE = 'CRA';

    public const RELIGIOUS = 'CRE';

    public const SCIENCE_TECH = 'SCT';

    public const AGRICULTURE_NUTRITION = 'AGN';

    public const SOCIAL_STUDIES = 'SST';

    public const PRE_TECHNICAL = 'PTS';

    public const AGRICULTURE = 'AGR';

    public const INTEGRATED_SCIENCE = 'ISC';

    public const LISTENING_SPEAKING = 'LSP';

    public const WRITING = 'WRI';

    public const INTEGRATED = 'INT';

    public static function spec(string $code): SubjectSpec
    {
        [$name, $order] = match ($code) {
            self::MATHS => ['Mathematics', 1],
            self::ENGLISH => ['English', 2],
            self::READING => ['Reading', 3],
            self::KISWAHILI => ['Kiswahili', 4],
            self::KUSOMA => ['Kusoma', 5],
            self::ENVIRONMENTAL => ['Environmental Activities', 6],
            self::SCIENCE_TECH => ['Science and Technology', 7],
            self::INTEGRATED_SCIENCE => ['Integrated Science', 8],
            self::AGRICULTURE_NUTRITION => ['Agriculture and Nutrition', 9],
            self::AGRICULTURE => ['Agriculture', 10],
            self::SOCIAL_STUDIES => ['Social Studies', 11],
            self::PRE_TECHNICAL => ['Pre-Technical Studies', 12],
            self::CREATIVE => ['Creative Arts', 13],
            self::RELIGIOUS => ['Christian Religious Education', 14],
            self::LISTENING_SPEAKING => ['Listening and Speaking', 20],
            self::WRITING => ['Writing', 21],
            self::INTEGRATED => ['Integrated Studies', 22],
        };

        return new SubjectSpec($code, $name, $order);
    }
}
