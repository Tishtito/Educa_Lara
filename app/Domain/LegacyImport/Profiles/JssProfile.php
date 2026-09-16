<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport\Profiles;

use App\Domain\Curriculum\Enums\AggregationRule;
use App\Domain\LegacyImport\Profiles\SubjectCatalogue as S;

/**
 * Junior secondary (Grades 7-9), legacy JSS. Nine subjects, all counted.
 *
 * English and Kiswahili switched from a single mark to Paper 1 + Paper 2 part-way
 * through Term 1 2026 (from the Term 1 End-Term on), so both variants exist in
 * 2026 and each exam uses the one its data has. Papers are raw marks out of 50:
 * JSS has no marks_out_of table, the legacy controller hardcoded 50, and no
 * stored paper mark exceeds 50.
 */
final class JssProfile implements LegacyProfile
{
    public function system(): string
    {
        return 'jss';
    }

    public function metaColumns(): array
    {
        return ['result_id', 'exam_id', 'student_id', 'student_class_id', 'total_marks', 'Total_marks',
            'position', 'stream_position', 'created_at', 'updated_at', 'deleted_at'];
    }

    public function totalColumn(): string
    {
        return 'total_marks';
    }

    public function subjects(): array
    {
        return array_map(S::spec(...), [S::MATHS, S::ENGLISH, S::KISWAHILI, S::PRE_TECHNICAL, S::AGRICULTURE,
            S::CREATIVE, S::RELIGIOUS, S::SOCIAL_STUDIES, S::INTEGRATED_SCIENCE]);
    }

    public function variants(int $year): array
    {
        $single = fn (string $code, string $column) => new SubjectVariant(
            $code, AggregationRule::SINGLE, [new PaperColumn($column, 'Paper 1', storedAsPercentage: true)],
        );

        return [
            S::MATHS => [$single(S::MATHS, 'Math')],
            S::ENGLISH => [
                new SubjectVariant(S::ENGLISH, AggregationRule::PERCENTAGE_OF_COMBINED_MAX, [
                    new PaperColumn('Paper1English', 'Paper 1', storedAsPercentage: false, defaultMax: 50),
                    new PaperColumn('Paper2English', 'Paper 2', storedAsPercentage: false, defaultMax: 50),
                ], evidenceColumns: ['English']),
                $single(S::ENGLISH, 'English'),
            ],
            S::KISWAHILI => [
                new SubjectVariant(S::KISWAHILI, AggregationRule::PERCENTAGE_OF_COMBINED_MAX, [
                    new PaperColumn('paper1Kiswahili', 'Paper 1', storedAsPercentage: false, defaultMax: 50),
                    new PaperColumn('paper2Kiswahili', 'Paper 2', storedAsPercentage: false, defaultMax: 50),
                ], evidenceColumns: ['Kiswahili']),
                $single(S::KISWAHILI, 'Kiswahili'),
            ],
            S::PRE_TECHNICAL => [$single(S::PRE_TECHNICAL, 'Technical')],
            S::AGRICULTURE => [$single(S::AGRICULTURE, 'Agriculture')],
            S::CREATIVE => [$single(S::CREATIVE, 'Creative')],
            S::RELIGIOUS => [$single(S::RELIGIOUS, 'Religious')],
            S::SOCIAL_STUDIES => [$single(S::SOCIAL_STUDIES, 'SST')],
            S::INTEGRATED_SCIENCE => [$single(S::INTEGRATED_SCIENCE, 'Science')],
        ];
    }

    public function marksOutOfAliases(): array
    {
        return [];
    }

    public function legacySubjectNames(): array
    {
        return ['Math' => S::MATHS, 'English' => S::ENGLISH, 'Kiswahili' => S::KISWAHILI, 'Creative' => S::CREATIVE,
            'Technical' => S::PRE_TECHNICAL, 'Agriculture' => S::AGRICULTURE, 'Religious' => S::RELIGIOUS,
            'SST' => S::SOCIAL_STUDIES, 'Science' => S::INTEGRATED_SCIENCE];
    }
}
