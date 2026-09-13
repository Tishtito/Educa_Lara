<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Examination\Enums\ExamStatus as S;
use PHPUnit\Framework\TestCase;

class ExamStatusTransitionsTest extends TestCase
{
    public function test_the_full_path_is_allowed(): void
    {
        $path = [S::DRAFT, S::OPEN, S::MARKING, S::LOCKED, S::PUBLISHED, S::ARCHIVED];

        for ($i = 0; $i < count($path) - 1; $i++) {
            $this->assertTrue($path[$i]->canTransitionTo($path[$i + 1]), "{$path[$i]->value} -> {$path[$i + 1]->value}");
        }
    }

    public function test_reopening_is_allowed_before_publication_only(): void
    {
        $this->assertTrue(S::OPEN->canTransitionTo(S::DRAFT));
        $this->assertTrue(S::LOCKED->canTransitionTo(S::MARKING));
        $this->assertFalse(S::PUBLISHED->canTransitionTo(S::MARKING));
        $this->assertFalse(S::PUBLISHED->canTransitionTo(S::LOCKED));
        $this->assertFalse(S::ARCHIVED->canTransitionTo(S::PUBLISHED));
    }

    public function test_steps_cannot_be_skipped(): void
    {
        $this->assertFalse(S::DRAFT->canTransitionTo(S::MARKING));
        $this->assertFalse(S::DRAFT->canTransitionTo(S::PUBLISHED));
        $this->assertFalse(S::OPEN->canTransitionTo(S::LOCKED));
        $this->assertFalse(S::MARKING->canTransitionTo(S::PUBLISHED));
    }

    public function test_no_state_transitions_to_itself(): void
    {
        foreach (S::cases() as $status) {
            $this->assertFalse($status->canTransitionTo($status), $status->value);
        }
    }

    public function test_max_marks_freeze_when_marking_closes(): void
    {
        $this->assertSame(
            [S::DRAFT, S::OPEN, S::MARKING],
            array_values(array_filter(S::cases(), fn (S $s) => $s->allowsMaxMarksChange())),
        );
    }
}
