<?php

declare(strict_types=1);

namespace App\Domain\Curriculum;

use App\Domain\Academics\Models\Level;
use App\Domain\Curriculum\Enums\AggregationRule;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Curriculum\Models\Subject;
use App\Domain\Curriculum\Services\CurriculumService;
use App\Domain\LegacyImport\Profiles\SubjectCatalogue as S;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;

/**
 * The CBC subjects a school starts with, per level, so a new school can set an
 * exam without first building its curriculum by hand.
 *
 * Codes and names are the legacy SubjectCatalogue's, so a school that later
 * imports its legacy data attaches to these subjects rather than getting a
 * second "Mathematics". English and Kiswahili are two papers where legacy
 * marked them that way — lower-classes Reading + Grammar and Kusoma + Lugha
 * (2026), JSS Paper 1 + Paper 2 — and each exam chooses which papers it sets.
 *
 * apply() only ever adds. A subject the school already offers at a level is
 * left exactly as the school set it up, whatever its papers or rule; running it
 * again adds nothing.
 */
class DefaultCurriculum
{
    private const SINGLE = [['Paper 1', 100]];

    public function __construct(
        private readonly CurriculumService $curriculum,
        private readonly TenantManager $tenancy,
    ) {}

    /**
     * level code => list of [subject code, papers [[name, out of], ...]], in report order.
     *
     * @return array<string, list<array{0: string, 1: list<array{0: string, 1: int}>}>>
     */
    public static function lists(): array
    {
        return [
            Level::LOWER_PRIMARY => [
                [S::MATHS, self::SINGLE],
                [S::ENGLISH, [['Reading', 50], ['Grammar', 50]]],
                [S::KISWAHILI, [['Kusoma', 50], ['Lugha', 50]]],
                [S::ENVIRONMENTAL, self::SINGLE],
                [S::CREATIVE, self::SINGLE],
                [S::RELIGIOUS, self::SINGLE],
            ],
            Level::UPPER_PRIMARY => [
                [S::MATHS, self::SINGLE],
                [S::ENGLISH, self::SINGLE],
                [S::KISWAHILI, self::SINGLE],
                [S::SCIENCE_TECH, self::SINGLE],
                [S::SOCIAL_STUDIES, self::SINGLE],
                [S::AGRICULTURE_NUTRITION, self::SINGLE],
                [S::CREATIVE, self::SINGLE],
                [S::RELIGIOUS, self::SINGLE],
            ],
            Level::JUNIOR_SECONDARY => [
                [S::ENGLISH, [['Paper 1', 50], ['Paper 2', 50]]],
                [S::MATHS, self::SINGLE],
                [S::KISWAHILI, [['Paper 1', 50], ['Paper 2', 50]]],
                [S::CREATIVE, self::SINGLE],
                [S::PRE_TECHNICAL, self::SINGLE],
                [S::AGRICULTURE, self::SINGLE],
                [S::SOCIAL_STUDIES, self::SINGLE],
                [S::INTEGRATED_SCIENCE, self::SINGLE],
                [S::RELIGIOUS, self::SINGLE],
            ],
        ];
    }

    /** @return array{subjects_added: int, level_subjects_added: int} */
    public function apply(School $school): array
    {
        return $this->tenancy->asSchool($school, fn () => DB::transaction(function () use ($school) {
            $levels = Level::query()->whereIn('code', array_keys(self::lists()))->pluck('id', 'code');
            $added = ['subjects_added' => 0, 'level_subjects_added' => 0];

            foreach (self::lists() as $levelCode => $subjects) {
                $levelId = $levels[$levelCode] ?? null;

                if ($levelId === null) {
                    continue;   // reference data not seeded; nothing to attach to
                }

                foreach ($subjects as $order => [$code, $papers]) {
                    $subject = $this->subject($school, $code, $added);

                    $offered = LevelSubject::query()
                        ->where('level_id', $levelId)
                        ->where('subject_id', $subject->id)
                        ->exists();

                    if ($offered) {
                        continue;
                    }

                    $this->curriculum->create($school->id, [
                        'level_id' => $levelId,
                        'subject_id' => $subject->id,
                        'aggregation_rule' => (count($papers) > 1 ? AggregationRule::PERCENTAGE_OF_COMBINED_MAX : AggregationRule::SINGLE)->value,
                        'scale_max' => 100,
                        'counts_toward_total' => true,
                        'display_order' => $order + 1,
                        'is_active' => true,
                    ], array_map(fn (array $paper) => ['name' => $paper[0], 'default_max_marks' => $paper[1], 'weight' => 1], $papers));

                    $added['level_subjects_added']++;
                }
            }

            return $added;
        }));
    }

    /**
     * The school's subject for this catalogue code: by code, else by the same
     * name (the name_norm unique index would refuse a duplicate), else new. A
     * soft-deleted match is restored rather than colliding with it.
     *
     * @param  array{subjects_added: int, level_subjects_added: int}  $added
     */
    private function subject(School $school, string $code, array &$added): Subject
    {
        $spec = S::spec($code);

        $existing = Subject::query()->withTrashed()->where('code', $code)->first()
            ?? Subject::query()->withTrashed()->where('name_norm', Subject::normalise($spec->name))->first();

        if ($existing !== null) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            return $existing;
        }

        $added['subjects_added']++;

        return Subject::query()->create([
            'school_id' => $school->id,
            'code' => $code,
            'name' => $spec->name,
            'short_name' => $code,
            'is_active' => true,
        ]);
    }
}
