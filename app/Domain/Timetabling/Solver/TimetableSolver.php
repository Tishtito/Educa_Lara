<?php

declare(strict_types=1);

namespace App\Domain\Timetabling\Solver;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Places every class's weekly lessons so that no class and no teacher is in
 * two places at once.
 *
 * The CBC generator (TimetableController::generate) placed lessons in a random
 * order and, the moment one did not fit, threw the whole school away and
 * started again — up to 80 times — then reported only the last failure. This
 * keeps its good ideas (prefer days without the subject, avoid back-to-back
 * repeats) and changes the search:
 *
 *  1. Hardest lessons first: doubles, then the busiest teachers and fullest
 *     classes, instead of a random order.
 *  2. Repair instead of restart: a lesson with nowhere to go evicts at most two
 *     lessons that are in its way and re-places them, two levels deep. Only
 *     when that fails does the attempt continue without it, and a fresh
 *     attempt (new ordering) runs only if something was left over.
 *  3. Once everything fits, local search moves and swaps lessons while that
 *     lowers the soft cost (repeats in a day, back-to-back repeats, uneven days).
 *  4. Seeded: the same input and seed give the same timetable, so a run can be
 *     reproduced from the seed stored on it.
 *
 * Hard rules: one lesson per class per slot; a teacher never in overlapping
 * times (clock times, not period numbers — levels may run different bells);
 * at most max_per_day lessons of a subject per class per day; a double lesson
 * takes two consecutive periods with no break between; locked lessons stay put;
 * a teacher's unavailable times are never used.
 */
final class TimetableSolver
{
    private const SPREAD_WEIGHT = 4.0;

    private const ADJACENT_WEIGHT = 3.0;

    private const CLASS_BALANCE_WEIGHT = 0.3;

    private const TEACHER_BALANCE_WEIGHT = 0.5;

    private const REPAIR_DEPTH = 2;

    private const REPAIR_TRIES = 12;

    private const MAX_BLOCKERS = 2;

    private Randomizer $rng;

    // --- fixed for one solve ------------------------------------------------

    /** @var array<int, list<array{day: int, period: int, tid: int}>> class => slot index => slot */
    private array $slots = [];

    /** @var array<int, array<int, list<int>>> class => day => slot indices in period order */
    private array $byDay = [];

    /** @var array<int, array<int, int>> class => slot index => its position within byDay */
    private array $dayIndex = [];

    /** @var array<int, array<int, int>> class => slot index => the next slot when the two can hold a double */
    private array $pairNext = [];

    /** @var array<int, list<int>> time id => time ids on the same day that overlap it (itself included) */
    private array $overlaps = [];

    /** @var array<int, array<int, true>> teacher => time ids they cannot teach */
    private array $unavailable = [];

    /** @var list<int> every school day that appears in any class's week */
    private array $allDays = [];

    /** @var list<array{class: int, subject: int, teacher: int, size: int, max: int, locked: bool, fixed: ?list<int>}> */
    private array $units = [];

    // --- one attempt -------------------------------------------------------

    /** @var array<int, array<int, int>> class => slot index => unit */
    private array $classSlot = [];

    /** @var array<int, array<int, int>> teacher => time id => unit */
    private array $teacherAt = [];

    /** @var array<int, array<int, array<int, int>>> class => subject => day => lessons */
    private array $subjectDay = [];

    /** @var array<int, array<int, int>> class => day => lessons */
    private array $classDay = [];

    /** @var array<int, array<int, int>> teacher => day => lessons */
    private array $teacherDay = [];

    /** @var array<int, list<int>> unit => slot indices it occupies */
    private array $unitPos = [];

    /** @var list<array{0: string, 1: int, 2?: list<int>}> undo log of place/remove */
    private array $journal = [];

    private int $repairs = 0;

    public function solve(SolverInput $input): SolverResult
    {
        $started = hrtime(true);
        $deadline = $started + $input->timeLimitMs * 1_000_000;
        $this->rng = new Randomizer(new Mt19937($input->seed));
        $this->prepare($input);

        $best = null;
        $attempts = 0;

        while ($attempts < max(1, $input->maxAttempts)) {
            $attempts++;
            $unplaced = $this->attempt();

            if ($unplaced === []) {
                $this->improve($input->improvementPasses, $deadline);

                return new SolverResult(true, $this->lessons(), [], $this->stats($input, $attempts, $started));
            }

            if ($best === null || count($unplaced) < count($best)) {
                $best = $this->describeUnplaced($unplaced);
            }

            if (hrtime(true) > $deadline) {
                break;
            }
        }

        return new SolverResult(false, [], $best ?? [], $this->stats($input, $attempts, $started));
    }

    // ------------------------------------------------------------ preparation

    private function prepare(SolverInput $input): void
    {
        $this->slots = $this->byDay = $this->dayIndex = $this->pairNext = [];
        $this->overlaps = $this->unavailable = $this->units = [];
        $this->repairs = 0;

        // Every distinct (day, start, end) becomes a time id; teachers are
        // booked against time ids, and a clash is any overlapping id.
        $times = [];
        $timeId = function (int $day, int $start, int $end) use (&$times): int {
            $key = "{$day}:{$start}:{$end}";

            return $times[$key] ??= count($times);
        };
        $timeOf = [];

        foreach ($input->slots as $class => $classSlots) {
            usort($classSlots, fn ($a, $b) => [$a['day'], $a['period']] <=> [$b['day'], $b['period']]);

            foreach ($classSlots as $i => $slot) {
                $tid = $timeId($slot['day'], $slot['start'], $slot['end']);
                $timeOf[$tid] = [$slot['day'], $slot['start'], $slot['end']];
                $this->slots[$class][$i] = ['day' => $slot['day'], 'period' => $slot['period'], 'tid' => $tid];
                $this->byDay[$class][$slot['day']][] = $i;
            }

            foreach ($this->byDay[$class] ?? [] as $indices) {
                foreach ($indices as $k => $i) {
                    $this->dayIndex[$class][$i] = $k;
                    $next = $indices[$k + 1] ?? null;

                    // Consecutive sequence numbers: a break or lunch has a
                    // sequence of its own, so a gap means one sits between.
                    if ($next !== null && $this->slots[$class][$next]['period'] === $this->slots[$class][$i]['period'] + 1) {
                        $this->pairNext[$class][$i] = $next;
                    }
                }
            }
        }

        foreach ($timeOf as $tid => [$day, $start, $end]) {
            foreach ($timeOf as $other => [$otherDay, $otherStart, $otherEnd]) {
                if ($day === $otherDay && $start < $otherEnd && $otherStart < $end) {
                    $this->overlaps[$tid][] = $other;
                }
            }
        }

        $this->allDays = array_values(array_unique(array_map(fn ($t) => $t[0], $timeOf)));
        sort($this->allDays);

        foreach ($input->unavailable as $teacher => $windows) {
            foreach ($windows as $window) {
                foreach ($timeOf as $tid => [$day, $start, $end]) {
                    if ($day === $window['day'] && $start < $window['end'] && $window['start'] < $end) {
                        $this->unavailable[$teacher][$tid] = true;
                    }
                }
            }
        }

        $this->buildUnits($input);
    }

    private function buildUnits(SolverInput $input): void
    {
        $requirementOf = [];
        foreach ($input->requirements as $r => $requirement) {
            $requirementOf[$requirement['class'].':'.$requirement['subject']] = $r;
        }

        // Locked lessons are fixed units, and count against their requirement.
        $lockedLessons = array_fill(0, count($input->requirements), 0);
        $lockedPairs = array_fill(0, count($input->requirements), 0);
        $groups = [];

        foreach ($input->locked as $lesson) {
            $r = $requirementOf[$lesson['class'].':'.$lesson['subject']] ?? null;
            $index = $this->slotIndex($lesson['class'], $lesson['day'], $lesson['period']);

            // No longer required, or its period no longer exists: dropped.
            if ($r === null || $index === null || $lockedLessons[$r] >= $input->requirements[$r]['lessons']) {
                continue;
            }

            $lockedLessons[$r]++;

            if ($lesson['double_group'] !== null) {
                $groups[$r][$lesson['double_group']][] = $index;

                continue;
            }

            $this->addUnit($input->requirements[$r], [$index]);
        }

        foreach ($groups as $r => $byGroup) {
            foreach ($byGroup as $indices) {
                sort($indices);
                $isPair = count($indices) === 2 && ($this->pairNext[$input->requirements[$r]['class']][$indices[0]] ?? null) === $indices[1];

                if ($isPair) {
                    $lockedPairs[$r]++;
                    $this->addUnit($input->requirements[$r], $indices);

                    continue;
                }

                foreach ($indices as $index) {
                    $this->addUnit($input->requirements[$r], [$index]);
                }
            }
        }

        foreach ($input->requirements as $r => $requirement) {
            $remaining = max(0, $requirement['lessons'] - $lockedLessons[$r]);
            $doubles = min(max(0, $requirement['doubles'] - $lockedPairs[$r]), intdiv($remaining, 2));

            for ($i = 0; $i < $doubles; $i++) {
                $this->addUnit($requirement, null, 2);
            }

            for ($i = 0; $i < $remaining - 2 * $doubles; $i++) {
                $this->addUnit($requirement, null, 1);
            }
        }
    }

    /** @param  ?list<int>  $fixed */
    private function addUnit(array $requirement, ?array $fixed, int $size = 0): void
    {
        $this->units[] = [
            'class' => $requirement['class'],
            'subject' => $requirement['subject'],
            'teacher' => $requirement['teacher'],
            'size' => $fixed !== null ? count($fixed) : $size,
            'max' => max(1, $requirement['max_per_day']),
            'locked' => $fixed !== null,
            'fixed' => $fixed,
        ];
    }

    private function slotIndex(int $class, int $day, int $period): ?int
    {
        foreach ($this->slots[$class] ?? [] as $i => $slot) {
            if ($slot['day'] === $day && $slot['period'] === $period) {
                return $i;
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- attempt

    /** @return list<int> units left unplaced */
    private function attempt(): array
    {
        $this->classSlot = $this->teacherAt = $this->subjectDay = [];
        $this->classDay = $this->teacherDay = $this->unitPos = $this->journal = [];
        $unplaced = [];

        foreach ($this->units as $u => $unit) {
            if ($unit['locked']) {
                if ($this->isLegal($u, $unit['fixed'])) {
                    $this->place($u, $unit['fixed']);
                } else {
                    $unplaced[] = $u;
                }
            }
        }

        foreach ($this->order() as $u) {
            $position = $this->bestPosition($u);

            if ($position !== null) {
                $this->place($u, $position);
            } elseif (! $this->repair($u, self::REPAIR_DEPTH, [$u => true])) {
                $unplaced[] = $u;
            }
        }

        $this->journal = [];

        return $unplaced;
    }

    /** @return list<int> free units, hardest first */
    private function order(): array
    {
        $teacherLoad = $classLoad = $teacherTimes = [];

        foreach ($this->units as $unit) {
            $teacherLoad[$unit['teacher']] = ($teacherLoad[$unit['teacher']] ?? 0) + $unit['size'];
            $classLoad[$unit['class']] = ($classLoad[$unit['class']] ?? 0) + $unit['size'];

            foreach ($this->slots[$unit['class']] ?? [] as $slot) {
                $teacherTimes[$unit['teacher']][$slot['tid']] = true;
            }
        }

        $keys = [];
        foreach ($this->units as $u => $unit) {
            if ($unit['locked']) {
                continue;
            }

            $keys[$u] = $unit['size'] * 10
                + $teacherLoad[$unit['teacher']] / max(1, count($teacherTimes[$unit['teacher']] ?? []) - count($this->unavailable[$unit['teacher']] ?? []))
                + $classLoad[$unit['class']] / max(1, count($this->slots[$unit['class']] ?? []))
                + 1 / $unit['max']
                + $this->rng->getFloat(0, 0.05);
        }

        arsort($keys);

        return array_keys($keys);
    }

    /** @return list<list<int>> every position the unit's shape allows in its class, occupied or not */
    private function positions(int $u): array
    {
        $unit = $this->units[$u];
        $positions = [];

        foreach (array_keys($this->slots[$unit['class']] ?? []) as $i) {
            if ($unit['size'] === 1) {
                $positions[] = [$i];
            } elseif (isset($this->pairNext[$unit['class']][$i])) {
                $positions[] = [$i, $this->pairNext[$unit['class']][$i]];
            }
        }

        return $positions;
    }

    /** @param  list<int>  $position */
    private function isLegal(int $u, array $position): bool
    {
        $unit = $this->units[$u];
        $class = $unit['class'];
        $day = $this->slots[$class][$position[0]]['day'] ?? null;

        if ($day === null || ($this->subjectDay[$class][$unit['subject']][$day] ?? 0) + $unit['size'] > $unit['max']) {
            return false;
        }

        foreach ($position as $i) {
            if (! isset($this->slots[$class][$i]) || isset($this->classSlot[$class][$i])) {
                return false;
            }

            $tid = $this->slots[$class][$i]['tid'];

            if (isset($this->unavailable[$unit['teacher']][$tid])) {
                return false;
            }

            foreach ($this->overlaps[$tid] as $other) {
                if (isset($this->teacherAt[$unit['teacher']][$other])) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return ?list<int> */
    private function bestPosition(int $u): ?array
    {
        $best = null;
        $bestScore = INF;

        foreach ($this->positions($u) as $position) {
            if (! $this->isLegal($u, $position)) {
                continue;
            }

            $score = $this->score($u, $position);

            if ($score < $bestScore) {
                $best = $position;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * The soft cost of putting the unit here: lower is better. The CBC
     * generator sorted free slots by the same first two ideas (fewest of this
     * subject that day, then the lightest day).
     *
     * @param  list<int>  $position
     */
    private function score(int $u, array $position): float
    {
        $unit = $this->units[$u];
        $class = $unit['class'];
        $day = $this->slots[$class][$position[0]]['day'];

        $score = self::SPREAD_WEIGHT * ($this->subjectDay[$class][$unit['subject']][$day] ?? 0)
            + self::CLASS_BALANCE_WEIGHT * ($this->classDay[$class][$day] ?? 0)
            + self::TEACHER_BALANCE_WEIGHT * ($this->teacherDay[$unit['teacher']][$day] ?? 0)
            + $this->rng->getFloat(0, 0.5);

        $first = $this->dayIndex[$class][$position[0]];
        $last = $this->dayIndex[$class][$position[count($position) - 1]];

        foreach ([$first - 1, $last + 1] as $k) {
            $neighbour = $this->byDay[$class][$day][$k] ?? null;
            $occupant = $neighbour === null ? null : ($this->classSlot[$class][$neighbour] ?? null);

            if ($occupant !== null && $this->units[$occupant]['subject'] === $unit['subject']) {
                $score += self::ADJACENT_WEIGHT;
            }
        }

        return $score;
    }

    // ----------------------------------------------------------------- repair

    /**
     * Makes room for a unit that fits nowhere by evicting at most two lessons in
     * its way and placing them elsewhere (recursively, up to $depth levels).
     * All or nothing: on failure every move is undone.
     *
     * @param  array<int, true>  $tabu  units this repair chain must not evict
     */
    private function repair(int $u, int $depth, array $tabu): bool
    {
        $candidates = [];

        foreach ($this->positions($u) as $position) {
            $blockers = $this->blockers($u, $position);

            if ($blockers === null || $blockers === [] || count($blockers) > self::MAX_BLOCKERS) {
                continue;
            }

            foreach ($blockers as $b) {
                if (isset($tabu[$b])) {
                    continue 2;
                }
            }

            $candidates[] = [$position, $blockers];
        }

        $candidates = $this->rng->shuffleArray($candidates);
        usort($candidates, fn ($a, $b) => count($a[1]) <=> count($b[1]));

        foreach (array_slice($candidates, 0, self::REPAIR_TRIES) as [$position, $blockers]) {
            $mark = count($this->journal);

            foreach ($blockers as $b) {
                $this->remove($b);
            }

            if (! $this->isLegal($u, $position)) {
                $this->rollback($mark);

                continue;
            }

            $this->place($u, $position);
            $placedAll = true;

            foreach ($blockers as $b) {
                $elsewhere = $this->bestPosition($b);

                if ($elsewhere !== null) {
                    $this->place($b, $elsewhere);
                } elseif ($depth <= 1 || ! $this->repair($b, $depth - 1, $tabu + [$u => true, $b => true])) {
                    $placedAll = false;

                    break;
                }
            }

            if ($placedAll) {
                $this->repairs++;

                return true;
            }

            $this->rollback($mark);
        }

        return false;
    }

    /**
     * The placed units that stop $u going at $position, or null when evicting
     * cannot help (a locked lesson, the teacher's unavailable time, or the
     * subject's daily limit filled by lessons that would stay).
     *
     * @param  list<int>  $position
     * @return ?list<int>
     */
    private function blockers(int $u, array $position): ?array
    {
        $unit = $this->units[$u];
        $class = $unit['class'];
        $blockers = [];

        foreach ($position as $i) {
            if (isset($this->classSlot[$class][$i])) {
                $blockers[$this->classSlot[$class][$i]] = true;
            }

            $tid = $this->slots[$class][$i]['tid'];

            if (isset($this->unavailable[$unit['teacher']][$tid])) {
                return null;
            }

            foreach ($this->overlaps[$tid] as $other) {
                if (isset($this->teacherAt[$unit['teacher']][$other])) {
                    $blockers[$this->teacherAt[$unit['teacher']][$other]] = true;
                }
            }
        }

        $day = $this->slots[$class][$position[0]]['day'];
        $sameSubjectThatDay = $this->subjectDay[$class][$unit['subject']][$day] ?? 0;

        foreach (array_keys($blockers) as $b) {
            if ($this->units[$b]['locked']) {
                return null;
            }

            if ($this->units[$b]['class'] === $class && $this->units[$b]['subject'] === $unit['subject']) {
                $sameSubjectThatDay -= $this->units[$b]['size'];
            }
        }

        if ($sameSubjectThatDay + $unit['size'] > $unit['max']) {
            return null;
        }

        return array_keys($blockers);
    }

    // ------------------------------------------------------------ improvement

    private function improve(int $passes, int $deadline): void
    {
        $movable = array_keys(array_filter($this->units, fn ($unit) => ! $unit['locked']));

        if ($movable === []) {
            return;
        }

        $iterations = $passes * count($movable);

        for ($n = 0; $n < $iterations && hrtime(true) < $deadline; $n++) {
            $u = $movable[$this->rng->getInt(0, count($movable) - 1)];
            $unit = $this->units[$u];
            $swapWith = null;

            if ($this->rng->getInt(0, 1) === 1) {
                $v = $movable[$this->rng->getInt(0, count($movable) - 1)];
                $other = $this->units[$v];

                if ($v !== $u && $other['class'] === $unit['class'] && $other['size'] === $unit['size'] && $other['subject'] !== $unit['subject']) {
                    $swapWith = $v;
                }
            }

            $teachers = array_unique([$unit['teacher'], $swapWith === null ? $unit['teacher'] : $this->units[$swapWith]['teacher']]);
            $before = $this->localCost($unit['class'], $teachers);
            $mark = count($this->journal);
            $from = $this->unitPos[$u];

            if ($swapWith === null) {
                $this->remove($u);
                $legal = array_values(array_filter($this->positions($u), fn ($p) => $p !== $from && $this->isLegal($u, $p)));

                if ($legal === []) {
                    $this->rollback($mark);

                    continue;
                }

                $this->place($u, $legal[$this->rng->getInt(0, count($legal) - 1)]);
            } else {
                $to = $this->unitPos[$swapWith];
                $this->remove($u);
                $this->remove($swapWith);

                if (! $this->isLegal($u, $to)) {
                    $this->rollback($mark);

                    continue;
                }

                $this->place($u, $to);

                if (! $this->isLegal($swapWith, $from)) {
                    $this->rollback($mark);

                    continue;
                }

                $this->place($swapWith, $from);
            }

            if ($this->localCost($unit['class'], $teachers) < $before - 1e-9) {
                $this->journal = [];
            } else {
                $this->rollback($mark);
            }
        }

        $this->journal = [];
    }

    /** @param  list<int>  $teachers */
    private function localCost(int $class, array $teachers): float
    {
        $cost = $this->classCost($class);

        foreach ($teachers as $teacher) {
            $cost += $this->teacherCost($teacher);
        }

        return $cost;
    }

    private function totalCost(): float
    {
        $cost = 0.0;

        foreach (array_keys($this->slots) as $class) {
            $cost += $this->classCost($class);
        }

        foreach (array_unique(array_column($this->units, 'teacher')) as $teacher) {
            $cost += $this->teacherCost($teacher);
        }

        return $cost;
    }

    private function classCost(int $class): float
    {
        $cost = 0.0;
        $loads = [];

        foreach ($this->byDay[$class] ?? [] as $indices) {
            $previousUnit = null;
            $previousSubject = null;
            $occurrences = [];
            $load = 0;

            foreach ($indices as $i) {
                $u = $this->classSlot[$class][$i] ?? null;

                if ($u === null) {
                    $previousUnit = $previousSubject = null;

                    continue;
                }

                $load++;
                $subject = $this->units[$u]['subject'];

                if ($u !== $previousUnit) {
                    if ($subject === $previousSubject) {
                        $cost += self::ADJACENT_WEIGHT;
                    }

                    $occurrences[$subject] = ($occurrences[$subject] ?? 0) + 1;
                }

                $previousUnit = $u;
                $previousSubject = $subject;
            }

            foreach ($occurrences as $count) {
                $cost += self::SPREAD_WEIGHT * max(0, $count - 1);
            }

            $loads[] = $load;
        }

        return $cost + self::CLASS_BALANCE_WEIGHT * $this->variance($loads);
    }

    private function teacherCost(int $teacher): float
    {
        $loads = array_map(fn ($day) => $this->teacherDay[$teacher][$day] ?? 0, $this->allDays);

        return self::TEACHER_BALANCE_WEIGHT * $this->variance($loads);
    }

    /** @param  list<int>  $values  sum of squared deviations */
    private function variance(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        $mean = array_sum($values) / count($values);

        return array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values));
    }

    // ------------------------------------------------------------------ state

    /** @param  list<int>  $position */
    private function place(int $u, array $position, bool $record = true): void
    {
        $unit = $this->units[$u];
        $class = $unit['class'];
        $day = $this->slots[$class][$position[0]]['day'];

        foreach ($position as $i) {
            $this->classSlot[$class][$i] = $u;
            $this->teacherAt[$unit['teacher']][$this->slots[$class][$i]['tid']] = $u;
        }

        $this->subjectDay[$class][$unit['subject']][$day] = ($this->subjectDay[$class][$unit['subject']][$day] ?? 0) + $unit['size'];
        $this->classDay[$class][$day] = ($this->classDay[$class][$day] ?? 0) + $unit['size'];
        $this->teacherDay[$unit['teacher']][$day] = ($this->teacherDay[$unit['teacher']][$day] ?? 0) + $unit['size'];
        $this->unitPos[$u] = $position;

        if ($record) {
            $this->journal[] = ['p', $u];
        }
    }

    private function remove(int $u, bool $record = true): void
    {
        $unit = $this->units[$u];
        $class = $unit['class'];
        $position = $this->unitPos[$u];
        $day = $this->slots[$class][$position[0]]['day'];

        foreach ($position as $i) {
            unset($this->classSlot[$class][$i], $this->teacherAt[$unit['teacher']][$this->slots[$class][$i]['tid']]);
        }

        $this->subjectDay[$class][$unit['subject']][$day] -= $unit['size'];
        $this->classDay[$class][$day] -= $unit['size'];
        $this->teacherDay[$unit['teacher']][$day] -= $unit['size'];
        unset($this->unitPos[$u]);

        if ($record) {
            $this->journal[] = ['r', $u, $position];
        }
    }

    private function rollback(int $mark): void
    {
        while (count($this->journal) > $mark) {
            $entry = array_pop($this->journal);

            if ($entry[0] === 'p') {
                $this->remove($entry[1], false);
            } else {
                $this->place($entry[1], $entry[2], false);
            }
        }
    }

    // ----------------------------------------------------------------- output

    /** @return list<array{class: int, subject: int, teacher: int, day: int, period: int, double_group: ?int, locked: bool}> */
    private function lessons(): array
    {
        $lessons = [];
        $group = 0;

        foreach ($this->unitPos as $u => $position) {
            $unit = $this->units[$u];
            $doubleGroup = count($position) === 2 ? ++$group : null;

            foreach ($position as $i) {
                $slot = $this->slots[$unit['class']][$i];
                $lessons[] = [
                    'class' => $unit['class'],
                    'subject' => $unit['subject'],
                    'teacher' => $unit['teacher'],
                    'day' => $slot['day'],
                    'period' => $slot['period'],
                    'double_group' => $doubleGroup,
                    'locked' => $unit['locked'],
                ];
            }
        }

        usort($lessons, fn ($a, $b) => [$a['class'], $a['day'], $a['period']] <=> [$b['class'], $b['day'], $b['period']]);

        return $lessons;
    }

    /**
     * Why each left-over lesson did not fit, judged against the attempt's final
     * state, grouped per class and subject.
     *
     * @param  list<int>  $unplaced
     * @return list<array{class: int, subject: int, teacher: int, lessons: int, reason: string}>
     */
    private function describeUnplaced(array $unplaced): array
    {
        $grouped = [];

        foreach ($unplaced as $u) {
            $unit = $this->units[$u];
            $reason = $unit['locked'] ? SolverResult::REASON_LOCKED_CONFLICT : $this->reason($u);
            $key = "{$unit['class']}:{$unit['subject']}:{$reason}";

            $grouped[$key] ??= ['class' => $unit['class'], 'subject' => $unit['subject'], 'teacher' => $unit['teacher'], 'lessons' => 0, 'reason' => $reason];
            $grouped[$key]['lessons'] += $unit['size'];
        }

        return array_values($grouped);
    }

    private function reason(int $u): string
    {
        $unit = $this->units[$u];
        $positions = $this->positions($u);

        if ($positions === []) {
            return $unit['size'] === 2 && ($this->slots[$unit['class']] ?? []) !== []
                ? SolverResult::REASON_NO_DOUBLE_SLOT
                : SolverResult::REASON_CLASS_FULL;
        }

        $classFree = array_filter($positions, function ($position) use ($unit) {
            foreach ($position as $i) {
                if (isset($this->classSlot[$unit['class']][$i])) {
                    return false;
                }
            }

            return true;
        });

        if ($classFree === []) {
            return SolverResult::REASON_CLASS_FULL;
        }

        foreach ($classFree as $position) {
            $teacherFree = true;

            foreach ($position as $i) {
                $tid = $this->slots[$unit['class']][$i]['tid'];

                if (isset($this->unavailable[$unit['teacher']][$tid])) {
                    $teacherFree = false;
                }

                foreach ($this->overlaps[$tid] as $other) {
                    if (isset($this->teacherAt[$unit['teacher']][$other])) {
                        $teacherFree = false;
                    }
                }
            }

            if ($teacherFree) {
                return SolverResult::REASON_MAX_PER_DAY;
            }
        }

        return SolverResult::REASON_TEACHER_BUSY;
    }

    /** @return array{attempts: int, repairs: int, cost: float, lessons: int, milliseconds: int, seed: int} */
    private function stats(SolverInput $input, int $attempts, int $started): array
    {
        return [
            'attempts' => $attempts,
            'repairs' => $this->repairs,
            'cost' => round($this->unitPos === [] ? 0.0 : $this->totalCost(), 2),
            'lessons' => array_sum(array_map(fn ($p) => count($p), $this->unitPos)),
            'milliseconds' => intdiv(hrtime(true) - $started, 1_000_000),
            'seed' => $input->seed,
        ];
    }
}
