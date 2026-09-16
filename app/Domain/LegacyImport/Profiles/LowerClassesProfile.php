<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport\Profiles;

use App\Domain\Curriculum\Enums\AggregationRule;
use App\Domain\LegacyImport\Profiles\SubjectCatalogue as S;

/**
 * Lower primary (Grades 1-3), legacy lower-classes.
 *
 * Measured from production:
 *   2025  every subject single-paper and counted, including Reading and Kusoma,
 *         which were subjects in their own right (Total_marks = the 8 columns).
 *   2026  English = Reading (RDG) + Grammar (GRM) and Kiswahili = Kusoma (KUS) +
 *         Lugha (LUG), each a percentage of combined max. The first 2026 exam
 *         still used a single English/Kiswahili mark for some classes, which the
 *         single variant covers.
 *   Stray columns with a handful of values — LS/SP (all zero), WRI, Integrated —
 *   import as their own subjects that do not count towards the total, so no mark
 *   is lost and none distorts a total.
 */
final class LowerClassesProfile implements LegacyProfile
{
    public function system(): string
    {
        return 'lower_classes';
    }

    public function metaColumns(): array
    {
        return ['result_id', 'exam_id', 'student_id', 'student_class_id', 'Total_marks', 'total_marks',
            'position', 'stream_position', 'created_at', 'updated_at', 'deleted_at'];
    }

    public function totalColumn(): string
    {
        return 'Total_marks';
    }

    public function subjects(): array
    {
        return array_map(S::spec(...), [S::MATHS, S::ENGLISH, S::READING, S::KISWAHILI, S::KUSOMA,
            S::ENVIRONMENTAL, S::CREATIVE, S::RELIGIOUS, S::LISTENING_SPEAKING, S::WRITING, S::INTEGRATED]);
    }

    public function variants(int $year): array
    {
        $single = fn (string $code, string $column, bool $counts = true) => new SubjectVariant(
            $code, AggregationRule::SINGLE, [new PaperColumn($column, 'Paper 1', storedAsPercentage: true)], $counts,
        );

        $variants = [
            S::MATHS => [$single(S::MATHS, 'Math')],
            S::ENVIRONMENTAL => [$single(S::ENVIRONMENTAL, 'Enviromental')],
            S::CREATIVE => [$single(S::CREATIVE, 'Creative')],
            S::RELIGIOUS => [$single(S::RELIGIOUS, 'Religious')],
            S::READING => [$single(S::READING, 'Reading')],
            S::KUSOMA => [$single(S::KUSOMA, 'Kusoma')],
            S::LISTENING_SPEAKING => [$single(S::LISTENING_SPEAKING, 'LS/SP', false)],
            S::WRITING => [$single(S::WRITING, 'WRI', false)],
            S::INTEGRATED => [$single(S::INTEGRATED, 'Integrated', false)],
            S::ENGLISH => [$single(S::ENGLISH, 'English')],
            S::KISWAHILI => [$single(S::KISWAHILI, 'Kiswahili')],
        ];

        if ($year >= 2026) {
            array_unshift($variants[S::ENGLISH], new SubjectVariant(S::ENGLISH, AggregationRule::PERCENTAGE_OF_COMBINED_MAX, [
                new PaperColumn('RDG', 'Reading', storedAsPercentage: false),
                new PaperColumn('GRM', 'Grammar', storedAsPercentage: false),
            ], evidenceColumns: ['English']));

            array_unshift($variants[S::KISWAHILI], new SubjectVariant(S::KISWAHILI, AggregationRule::PERCENTAGE_OF_COMBINED_MAX, [
                new PaperColumn('KUS', 'Kusoma', storedAsPercentage: false),
                new PaperColumn('LUG', 'Lugha', storedAsPercentage: false),
            ], evidenceColumns: ['Kiswahili']));
        }

        return $variants;
    }

    public function marksOutOfAliases(): array
    {
        return ['Environmental' => 'Enviromental', 'Enviromental' => 'Enviromental'];
    }

    public function legacySubjectNames(): array
    {
        return [];
    }
}
