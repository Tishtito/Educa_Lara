<?php

declare(strict_types=1);

namespace Tests\Unit\LegacyImport;

use App\Domain\LegacyImport\Mapping\LegacyDates;
use App\Domain\LegacyImport\Mapping\MigrationMap;
use App\Domain\LegacyImport\Mapping\PersonName;
use PHPUnit\Framework\TestCase;

class LegacyValuesTest extends TestCase
{
    public function test_names_split_into_first_middle_and_last(): void
    {
        $this->assertSame(['first_name' => 'Amani', 'middle_name' => 'Juma', 'last_name' => 'Otieno'], PersonName::split('Amani Juma Otieno'));
        $this->assertSame(['first_name' => 'Baraka', 'middle_name' => null, 'last_name' => 'Mwangi'], PersonName::split(' Baraka   Mwangi '));
        $this->assertSame(['first_name' => 'Wanjiru', 'middle_name' => null, 'last_name' => ''], PersonName::split('Wanjiru'));
        $this->assertSame('Mary Wambui Njeri Kamau', implode(' ', array_filter(PersonName::split('Mary  Wambui Njeri Kamau'))));
    }

    public function test_names_compare_without_case_or_spacing(): void
    {
        $this->assertSame(PersonName::normalise('Duplicate  Name'), PersonName::normalise(' duplicate name'));
        $this->assertSame('chébet rono', PersonName::normalise('CHÉBET RONO'));
        $this->assertSame('', PersonName::normalise(null));
    }

    public function test_the_exam_year_comes_from_the_creation_date(): void
    {
        $this->assertSame(2026, LegacyDates::examYear(['academic_year' => '0000', 'date_created' => '2026-02-11 09:00:00']));
        $this->assertSame(2026, LegacyDates::examYear(['academic_year' => '2025', 'date_created' => '2026-02-11 09:00:00']));
        $this->assertSame(2025, LegacyDates::examYear(['academic_year' => null, 'date_created' => '2025-10-14 09:00:00']));
    }

    public function test_the_stored_year_is_only_a_fallback_and_zero_years_are_unknown(): void
    {
        $this->assertSame(2025, LegacyDates::examYear(['academic_year' => '2025', 'date_created' => null]));
        $this->assertSame(2025, LegacyDates::examYear(['academic_year' => '2025', 'date_created' => '0000-00-00 00:00:00']));
        $this->assertSame(2025, LegacyDates::examYear(['academic_year' => '2025', 'date_created' => 'not a date']));
        $this->assertNull(LegacyDates::examYear(['academic_year' => '0000', 'date_created' => null]));
        $this->assertNull(LegacyDates::examYear(['academic_year' => null, 'date_created' => '']));
    }

    public function test_row_checksums_ignore_key_order_and_driver_types(): void
    {
        $this->assertSame(MigrationMap::checksum(['a' => 1, 'b' => '2']), MigrationMap::checksum(['b' => 2, 'a' => '1']));
        $this->assertSame(MigrationMap::checksum([['x' => 1, 'y' => 2]]), MigrationMap::checksum([['y' => '2', 'x' => '1']]));
    }

    public function test_row_checksums_see_every_real_change(): void
    {
        $row = ['Math' => 80, 'English' => null, 'name' => 'Amani'];

        $this->assertNotSame(MigrationMap::checksum($row), MigrationMap::checksum(['Math' => 81] + $row));
        $this->assertNotSame(MigrationMap::checksum($row), MigrationMap::checksum(['English' => 0] + $row));     // null vs 0
        $this->assertNotSame(MigrationMap::checksum($row), MigrationMap::checksum(['English' => ''] + $row));    // null vs ''
        $this->assertNotSame(MigrationMap::checksum($row), MigrationMap::checksum($row + ['WRI' => 1]));
        $this->assertNotSame(MigrationMap::checksum([['a' => 1], ['a' => 2]]), MigrationMap::checksum([['a' => 2], ['a' => 1]]));   // list order matters
    }
}
