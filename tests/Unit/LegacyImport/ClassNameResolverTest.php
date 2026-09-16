<?php

declare(strict_types=1);

namespace Tests\Unit\LegacyImport;

use App\Domain\LegacyImport\Mapping\ClassNameResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ClassNameResolverTest extends TestCase
{
    /** Spellings measured in the production databases. */
    public static function spellings(): array
    {
        return [
            'snake case' => ['grade_2east', 'Grade 2 East'],
            'upper case with spaces' => ['GRADE 3 EAST', 'Grade 3 East'],
            'no stream' => ['grade_3', 'Grade 3'],
            'bare number' => ['7', 'Grade 7'],
            'number and stream' => ['8_west', 'Grade 8 West'],
            'run together' => ['9east', 'Grade 9 East'],
            'padded, hyphenated' => ['  Grade-4  North ', 'Grade 4 North'],
            'colour stream' => ['g5 blue', 'Grade 5 Blue'],
            'letter stream' => ['6b', 'Grade 6 B'],
        ];
    }

    #[DataProvider('spellings')]
    public function test_spellings_resolve_to_one_class(string $raw, string $expected): void
    {
        $parsed = (new ClassNameResolver)->parse($raw);

        $this->assertNotNull($parsed);
        $this->assertFalse($parsed->isGraduationMarker);
        $this->assertSame($expected, $parsed->name());
    }

    public function test_spellings_of_the_same_class_share_a_key(): void
    {
        $resolver = new ClassNameResolver;

        $this->assertSame($resolver->parse('grade_3east')->key(), $resolver->parse('GRADE 3 EAST')->key());
        $this->assertNotSame($resolver->parse('grade_3')->key(), $resolver->parse('grade_3east')->key());
    }

    public function test_completed_is_a_graduation_marker_not_a_class(): void
    {
        foreach (['Completed', 'COMPLETED', 'finished', 'Graduated'] as $raw) {
            $this->assertTrue((new ClassNameResolver)->parse($raw)->isGraduationMarker, $raw);
        }
    }

    public function test_anything_else_is_not_guessed(): void
    {
        foreach (['6_test', '', null, 'grade', 'grade 10', '0', 'Form 1', 'grade 2 east west', 'staff room'] as $raw) {
            $this->assertNull((new ClassNameResolver)->parse($raw), var_export($raw, true));
        }
    }
}
