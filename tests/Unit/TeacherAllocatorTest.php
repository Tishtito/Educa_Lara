<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Timetabling\Solver\TeacherAllocation;
use App\Domain\Timetabling\Solver\TeacherAllocator;
use PHPUnit\Framework\TestCase;

/** The allocator on its own: who teaches each class's subject, from who ticked it. */
class TeacherAllocatorTest extends TestCase
{
    private const MATHS = 1;

    private const ENGLISH = 2;

    private const MUSIC = 3;

    /** @return list<array{class: int, subject: int, lessons: int, group: int}> */
    private function classes(int $count, int $subject, int $lessons): array
    {
        return array_map(fn (int $c) => ['class' => $c, 'subject' => $subject, 'lessons' => $lessons, 'group' => intdiv($c + 1, 2)], range(1, $count));
    }

    public function test_loads_are_balanced_between_the_teachers_who_ticked_a_subject(): void
    {
        $result = (new TeacherAllocator)->allocate($this->classes(6, self::MATHS, 5), [self::MATHS => [10, 11, 12]], [10 => 40, 11 => 40, 12 => 40], seed: 7);

        $this->assertSame([], $result->unallocated);
        $this->assertCount(6, $result->teachers);
        $this->assertSame([10 => 10, 11 => 10, 12 => 10], $this->sorted($result->load));
    }

    public function test_the_scarcest_subject_is_decided_first_so_it_gets_its_only_teacher(): void
    {
        // Teacher 10 is the only one who teaches music, but also teaches maths.
        // Handing out maths first by load could fill 10 and leave music with nobody.
        $demands = [...$this->classes(2, self::MATHS, 5), ['class' => 1, 'subject' => self::MUSIC, 'lessons' => 5, 'group' => 1]];

        foreach (range(1, 20) as $seed) {
            $result = (new TeacherAllocator)->allocate($demands, [self::MATHS => [10, 11], self::MUSIC => [10]], [10 => 6, 11 => 10], seed: $seed);

            $this->assertSame([], $result->unallocated, "seed {$seed}");
            $this->assertSame(10, $result->teacherFor(1, self::MUSIC));
            $this->assertSame(11, $result->teacherFor(1, self::MATHS));
            $this->assertSame(11, $result->teacherFor(2, self::MATHS));
        }
    }

    public function test_a_teacher_is_never_given_more_than_their_capacity_and_what_does_not_fit_is_reported(): void
    {
        $demands = [...$this->classes(3, self::MATHS, 5), ['class' => 4, 'subject' => self::ENGLISH, 'lessons' => 4, 'group' => 2]];
        $result = (new TeacherAllocator)->allocate($demands, [self::MATHS => [10, 11]], [10 => 7, 11 => 7], seed: 3);

        foreach ($result->load as $teacher => $load) {
            $this->assertLessThanOrEqual(7, $load, "Teacher {$teacher} over capacity");
        }

        $reasons = array_column($result->unallocated, 'reason', 'subject');
        $this->assertCount(2, $result->unallocated);
        $this->assertSame(TeacherAllocation::REASON_NO_CAPACITY, $reasons[self::MATHS]);
        $this->assertSame(TeacherAllocation::REASON_NO_CANDIDATE, $reasons[self::ENGLISH]);
    }

    public function test_the_same_seed_gives_the_same_allocation(): void
    {
        $demands = [...$this->classes(8, self::MATHS, 4), ...$this->classes(8, self::ENGLISH, 3)];
        $candidates = [self::MATHS => [10, 11, 12, 13], self::ENGLISH => [11, 12, 13, 14]];
        $capacity = [10 => 40, 11 => 40, 12 => 40, 13 => 40, 14 => 40];

        $first = (new TeacherAllocator)->allocate($demands, $candidates, $capacity, seed: 99);
        $again = (new TeacherAllocator)->allocate($demands, $candidates, $capacity, seed: 99);

        $this->assertSame($first->teachers, $again->teachers);

        $different = false;
        foreach (range(100, 120) as $seed) {
            $different = $different || (new TeacherAllocator)->allocate($demands, $candidates, $capacity, seed: $seed)->teachers !== $first->teachers;
        }
        $this->assertTrue($different, 'Other seeds should give other allocations to choose from');
    }

    public function test_pins_are_kept_and_count_towards_their_teachers_load(): void
    {
        $demands = [...$this->classes(4, self::MATHS, 5), ['class' => 9, 'subject' => self::ENGLISH, 'lessons' => 10, 'group' => 5]];
        $result = (new TeacherAllocator)->allocate(
            $demands,
            [self::MATHS => [10, 11]],
            [10 => 40, 11 => 40],
            pinned: ['9:'.self::ENGLISH => 10],
            seed: 5,
        );

        $this->assertSame(10, $result->teacherFor(9, self::ENGLISH));
        // 10 already has 10 English lessons, so maths goes to 11 until they draw level.
        $this->assertSame([10 => 15, 11 => 15], $this->sorted($result->load));
        $this->assertSame([], $result->unallocated);
    }

    public function test_a_pin_is_kept_even_for_a_teacher_who_did_not_tick_the_subject(): void
    {
        $result = (new TeacherAllocator)->allocate($this->classes(1, self::MUSIC, 2), [], [], pinned: ['1:'.self::MUSIC => 42]);

        $this->assertSame(42, $result->teacherFor(1, self::MUSIC));
        $this->assertSame([], $result->unallocated);
    }

    /** @param  array<int, int>  $load */
    private function sorted(array $load): array
    {
        ksort($load);

        return $load;
    }
}
