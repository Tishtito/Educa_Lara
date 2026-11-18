<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport\Steps;

use App\Domain\LegacyImport\ImportContext;
use App\Domain\LegacyImport\Mapping\PersonName;
use App\Domain\LegacyImport\Models\LegacyDecision;
use App\Domain\LegacyImport\SourceContext;
use App\Domain\People\Models\Student;

/**
 * Students, with one record per child across the three sections.
 *
 * Legacy has no assessment numbers (pno is empty in lower and JSS and absent in
 * upper), so the same child in two sections can only be recognised by name and
 * by a move that makes sense. Decided with the school:
 *
 *   - merged automatically when the normalised name is unique in both sections
 *     AND the child's last class in one section is its final grade in year Y and
 *     their first class in the next section is its first grade in year Y+1
 *     (Grade 3 → 4, Grade 6 → 7);
 *   - any other name shared by adjacent sections becomes a student_match question
 *     per candidate pair, answered by the school (accept = same child);
 *   - everything else is a separate student, including children who share a name
 *     within one section.
 *
 * Assessment numbers are generated (LEG-L-00012) on creation only, so a real
 * number the school enters later is never overwritten by a re-run.
 */
final class StudentStep implements ImportStep
{
    public function name(): string
    {
        return 'students';
    }

    public function run(ImportContext $context): void
    {
        $people = $this->collect($context);
        $groups = $this->group($context, $people);

        foreach ($groups as $members) {
            $this->importGroup($context, $members, $people);
        }
    }

    /** @return array<string, array<string, mixed>> "source|id" => person */
    private function collect(ImportContext $context): array
    {
        $people = [];

        foreach ($context->sources as $source) {
            $classRows = $context->placementRows($source)->groupBy('student_id');

            foreach ($source->table('students', 'student_id') as $row) {
                $placements = [];

                foreach ($classRows->get($row['student_id'], collect()) as $classRow) {
                    $parsed = $context->parseClass($classRow['class']);
                    if ($parsed !== null && ! $parsed->isGraduationMarker && (int) $classRow['academic_year'] >= 2000) {
                        $placements[] = ['year' => (int) $classRow['academic_year'], 'grade' => $parsed->grade];
                    }
                }

                $people["{$source->key}|{$row['student_id']}"] = [
                    'source' => $source,
                    'row' => $row,
                    'norm' => PersonName::normalise($row['name']),
                    'placements' => $placements,
                ];
            }
        }

        return $people;
    }

    /**
     * Union of accepted matches into identity groups.
     *
     * @return list<list<string>>
     */
    private function group(ImportContext $context, array $people): array
    {
        $parent = array_combine(array_keys($people), array_keys($people));
        $find = function (string $x) use (&$parent, &$find): string {
            return $parent[$x] === $x ? $x : ($parent[$x] = $find($parent[$x]));
        };

        $keys = array_keys($context->sources);

        for ($i = 0; $i < count($keys) - 1; $i++) {
            [$from, $to] = [$context->sources[$keys[$i]], $context->sources[$keys[$i + 1]]];

            $fromByName = collect($people)->filter(fn ($p) => $p['source'] === $from)->groupBy('norm', preserveKeys: true);
            $toByName = collect($people)->filter(fn ($p) => $p['source'] === $to)->groupBy('norm', preserveKeys: true);

            foreach ($fromByName as $norm => $fromMatches) {
                $toMatches = $toByName->get($norm);

                if ($toMatches === null || $norm === '') {
                    continue;
                }

                foreach ($fromMatches as $fromKey => $a) {
                    foreach ($toMatches as $toKey => $b) {
                        $confident = $fromMatches->count() === 1 && $toMatches->count() === 1 && $this->movesUp($a, $b, $from, $to);

                        $answer = $context->decisions->propose(LegacyDecision::STUDENT_MATCH, "{$fromKey}|{$toKey}", [
                            'name' => $a['row']['name'],
                            $from->key => ['legacy_id' => $a['row']['student_id'], 'placements' => $a['placements'], 'status' => $a['row']['status'] ?? null],
                            $to->key => ['legacy_id' => $b['row']['student_id'], 'placements' => $b['placements'], 'status' => $b['row']['status'] ?? null],
                            'others_with_this_name' => ($fromMatches->count() - 1) + ($toMatches->count() - 1),
                            'question' => "Is this the same child in {$from->key} and {$to->key}?",
                        ], $confident ? LegacyDecision::ACCEPT : null);

                        if ($answer === LegacyDecision::ACCEPT) {
                            $parent[$find($fromKey)] = $find($toKey);
                            $context->report->count($this->name(), $confident ? 'merged automatically' : 'merged by school decision');
                        }
                    }
                }
            }
        }

        $groups = [];
        foreach (array_keys($people) as $key) {
            $groups[$find($key)][] = $key;
        }

        return array_values($groups);
    }

    /** Final grade of one section in year Y, first grade of the next in Y+1. */
    private function movesUp(array $a, array $b, SourceContext $from, SourceContext $to): bool
    {
        [, $fromMax] = $from->gradeRange();
        [$toMin] = $to->gradeRange();

        $lastA = collect($a['placements'])->sortBy('year')->last();
        $firstB = collect($b['placements'])->sortBy('year')->first();

        return $lastA !== null && $firstB !== null
            && $lastA['grade'] === $fromMax && $firstB['grade'] === $toMin
            && $firstB['year'] === $lastA['year'] + 1;
    }

    /** @param list<string> $members */
    private function importGroup(ImportContext $context, array $members, array $people): void
    {
        // Latest section's record is authoritative for name and status.
        $order = array_flip(array_keys($context->sources));
        usort($members, fn ($x, $y) => $order[explode('|', $x)[0]] <=> $order[explode('|', $y)[0]]);
        $latest = $people[end($members)];
        $earliest = $people[$members[0]];

        $existingId = null;
        foreach ($members as $key) {
            $p = $people[$key];
            $existingId ??= $context->map->targetId($p['source']->system, 'students', $p['row']['student_id'], 'students');
        }

        $rows = array_map(fn ($k) => $people[$k]['row'], $members);
        $unchanged = $existingId !== null && collect($members)->every(fn ($k) => $context->map->isUnchanged(
            $people[$k]['source']->system, 'students', $people[$k]['row']['student_id'], 'students', $people[$k]['row'],
        ));

        $attributes = PersonName::split((string) $latest['row']['name']) + [
            'status' => in_array($latest['row']['status'] ?? 'Active', ['Finished', 'Graduated'], true) ? 'graduated' : 'active',
        ];

        if ($existingId === null) {
            $student = Student::create($attributes + [
                'school_id' => $context->school->id,
                'assessment_no' => sprintf('LEG-%s-%05d', $earliest['source']->admissionPrefix, $earliest['row']['student_id']),
            ]);
            $context->report->count($this->name(), 'students created');
        } else {
            $student = Student::withTrashed()->findOrFail($existingId);

            if (! $unchanged) {
                $student->fill($attributes)->save();
                $context->report->count($this->name(), 'students updated');
            }
        }

        foreach ($members as $key) {
            $p = $people[$key];
            $context->students[$key] = $student->id;

            if (! $unchanged) {
                $context->map->record($p['source']->system, 'students', $p['row']['student_id'], 'students', $student->id, $p['row']);
            }
        }

        if (count($members) > 1) {
            $context->report->count($this->name(), 'legacy records merged into one student', count($members) - 1);
        }
    }
}
