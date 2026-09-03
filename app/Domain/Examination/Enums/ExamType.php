<?php

declare(strict_types=1);

namespace App\Domain\Examination\Enums;

/**
 * = legacy exams.exam_type ENUM('Weekly-Test','Opener','Mid-Term','End-Term'),
 * widened for CATs and mocks, which JSS ran but had to record as 'End-Term'.
 *
 * MIDTERM and ENDTERM are the pair that ReportsController@reportCombined places
 * side by side on one report card.
 */
enum ExamType: string
{
    case OPENER = 'opener';
    case MIDTERM = 'midterm';
    case ENDTERM = 'endterm';
    case CAT = 'cat';
    case MOCK = 'mock';
    case WEEKLY = 'weekly';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::OPENER => 'Opener',
            self::MIDTERM => 'Mid-Term',
            self::ENDTERM => 'End-Term',
            self::CAT => 'CAT',
            self::MOCK => 'Mock',
            self::WEEKLY => 'Weekly Test',
            self::OTHER => 'Other',
        };
    }

    /** The two types a combined report card pairs. */
    public function appearsOnCombinedReport(): bool
    {
        return in_array($this, [self::MIDTERM, self::ENDTERM], true);
    }
}
