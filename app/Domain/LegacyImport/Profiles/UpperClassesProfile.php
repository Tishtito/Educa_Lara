<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport\Profiles;

use App\Domain\Curriculum\Enums\AggregationRule;
use App\Domain\LegacyImport\Profiles\SubjectCatalogue as S;

/**
 * Upper primary (Grades 4-6), legacy upper-classes — not Upper-classes-combined:
 * production has no Gramma/Compo/Lugha/Insha columns. Eight single-paper subjects,
 * all counted (Total_marks = their sum in every current row), unchanged across years.
 */
final class UpperClassesProfile implements LegacyProfile
{
    public function system(): string
    {
        return 'upper_classes';
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
        return array_map(S::spec(...), [S::MATHS, S::ENGLISH, S::KISWAHILI, S::SCIENCE_TECH,
            S::AGRICULTURE_NUTRITION, S::CREATIVE, S::RELIGIOUS, S::SOCIAL_STUDIES]);
    }

    public function variants(int $year): array
    {
        $single = fn (string $code, string $column) => [new SubjectVariant(
            $code, AggregationRule::SINGLE, [new PaperColumn($column, 'Paper 1', storedAsPercentage: true)],
        )];

        return [
            S::MATHS => $single(S::MATHS, 'Math'),
            S::ENGLISH => $single(S::ENGLISH, 'English'),
            S::KISWAHILI => $single(S::KISWAHILI, 'Kiswahili'),
            S::SCIENCE_TECH => $single(S::SCIENCE_TECH, 'SciTech'),
            S::AGRICULTURE_NUTRITION => $single(S::AGRICULTURE_NUTRITION, 'AgricNutri'),
            S::CREATIVE => $single(S::CREATIVE, 'Creative'),
            S::RELIGIOUS => $single(S::RELIGIOUS, 'CRE'),
            S::SOCIAL_STUDIES => $single(S::SOCIAL_STUDIES, 'SST'),
        ];
    }

    public function marksOutOfAliases(): array
    {
        return ['Science & Technology' => 'SciTech', 'Agriculture & Nutrition' => 'AgricNutri'];
    }

    public function legacySubjectNames(): array
    {
        return [];
    }
}
