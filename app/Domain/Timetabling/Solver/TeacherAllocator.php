<?php

declare(strict_types=1);

namespace App\Domain\Timetabling\Solver;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Decides which teacher takes each class's subject, from the teachers who
 * ticked that subject. Pure: no database, deterministic for a seed, so the
 * generator can try another allocation (seed + 1) when the solver finds the
 * first one cannot be placed.
 *
 * The CBC generator chose a teacher per class lesson by lesson while placing
 * them, so a teacher's classes depended on placement order; here the whole
 * class-and-subject goes to one person before placement starts:
 *
 *   - scarcest first: the demand with the fewest candidates is decided first,
 *     so a subject only one person teaches gets that person before their time
 *     is spent on subjects others could have taken (ties: more lessons first,
 *     then seeded random);
 *   - least loaded: it goes to the candidate with the fewest lessons so far
 *     whose load stays within their capacity (ties: one who already teaches
 *     the subject in the same group — the other stream of the grade — then
 *     fewer distinct classes, then seeded random).
 *
 * Pinned demands are fixed up front and count towards their teacher's load.
 */
final class TeacherAllocator
{
    /**
     * @param  list<array{class: int, subject: int, lessons: int, group?: int}>  $demands  every class-subject to staff, pinned ones included
     * @param  array<int, list<int>>  $candidates  subject => teachers who can teach it
     * @param  array<int, int>  $capacity  teacher => most lessons a week they can take
     * @param  array<string, int>  $pinned  "class:subject" => teacher, already decided
     */
    public function allocate(array $demands, array $candidates, array $capacity, array $pinned = [], int $seed = 1): TeacherAllocation
    {
        $rng = new Randomizer(new Mt19937($seed));
        $teachers = [];
        $load = [];
        $classes = [];
        $groups = [];
        $open = [];

        foreach ($demands as $demand) {
            $key = "{$demand['class']}:{$demand['subject']}";

            if (isset($pinned[$key])) {
                $this->give($pinned[$key], $demand, $teachers, $load, $classes, $groups);
            } else {
                $open[] = $demand;
            }
        }

        // A random rank per teacher breaks the last ties, and a random order of
        // the demands breaks theirs (usort is stable since PHP 8.0).
        $everyone = array_values(array_unique(array_merge(array_keys($capacity), ...array_values($candidates ?: [[]]))));
        sort($everyone);
        $rank = array_flip($rng->shuffleArray($everyone));
        $open = $rng->shuffleArray($open);

        usort($open, fn (array $a, array $b) => [count($candidates[$a['subject']] ?? []), -$a['lessons']]
            <=> [count($candidates[$b['subject']] ?? []), -$b['lessons']]);

        $unallocated = [];

        foreach ($open as $demand) {
            $pool = array_values(array_unique($candidates[$demand['subject']] ?? []));

            if ($pool === []) {
                $unallocated[] = ['class' => $demand['class'], 'subject' => $demand['subject'], 'lessons' => $demand['lessons'], 'reason' => TeacherAllocation::REASON_NO_CANDIDATE];

                continue;
            }

            $fits = array_values(array_filter($pool, fn (int $t) => ($load[$t] ?? 0) + $demand['lessons'] <= ($capacity[$t] ?? 0)));

            if ($fits === []) {
                $unallocated[] = ['class' => $demand['class'], 'subject' => $demand['subject'], 'lessons' => $demand['lessons'], 'reason' => TeacherAllocation::REASON_NO_CAPACITY];

                continue;
            }

            $groupKey = isset($demand['group']) ? "{$demand['group']}:{$demand['subject']}" : null;

            usort($fits, fn (int $a, int $b) => [
                $load[$a] ?? 0,
                $groupKey !== null && isset($groups[$groupKey][$a]) ? 0 : 1,
                count($classes[$a] ?? []),
                $rank[$a] ?? PHP_INT_MAX,
            ] <=> [
                $load[$b] ?? 0,
                $groupKey !== null && isset($groups[$groupKey][$b]) ? 0 : 1,
                count($classes[$b] ?? []),
                $rank[$b] ?? PHP_INT_MAX,
            ]);

            $this->give($fits[0], $demand, $teachers, $load, $classes, $groups);
        }

        return new TeacherAllocation($teachers, $unallocated, $load, $seed);
    }

    /** @param  array{class: int, subject: int, lessons: int, group?: int}  $demand */
    private function give(int $teacher, array $demand, array &$teachers, array &$load, array &$classes, array &$groups): void
    {
        $teachers["{$demand['class']}:{$demand['subject']}"] = $teacher;
        $load[$teacher] = ($load[$teacher] ?? 0) + $demand['lessons'];
        $classes[$teacher][$demand['class']] = true;

        if (isset($demand['group'])) {
            $groups["{$demand['group']}:{$demand['subject']}"][$teacher] = true;
        }
    }
}
