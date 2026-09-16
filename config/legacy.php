<?php

declare(strict_types=1);

use App\Domain\LegacyImport\Profiles\JssProfile;
use App\Domain\LegacyImport\Profiles\LowerClassesProfile;
use App\Domain\LegacyImport\Profiles\UpperClassesProfile;

/*
|--------------------------------------------------------------------------
| Legacy import (docs/legacy-import.md)
|--------------------------------------------------------------------------
|
| One school's three legacy section databases, restored locally from cPanel
| exports. The importer only ever reads them; give the connection user SELECT
| and nothing else.
|
| Sources are listed in progression order: a pupil leaving the last grade of one
| section joins the first grade of the next.
|
*/

return [

    'sources' => [
        'lower' => [
            'system' => 'lower_classes',
            'connection' => 'legacy_lower',
            'level' => 'lower_primary',
            'profile' => LowerClassesProfile::class,
            'admission_prefix' => 'L',
        ],
        'upper' => [
            'system' => 'upper_classes',
            'connection' => 'legacy_upper',
            'level' => 'upper_primary',
            'profile' => UpperClassesProfile::class,
            'admission_prefix' => 'U',
        ],
        'jss' => [
            'system' => 'jss',
            'connection' => 'legacy_jss',
            'level' => 'junior_secondary',
            'profile' => JssProfile::class,
            'admission_prefix' => 'J',
        ],
    ],

    // Import reports; under storage/app/private, which is not web-served or committed.
    'report_directory' => 'legacy',

];
