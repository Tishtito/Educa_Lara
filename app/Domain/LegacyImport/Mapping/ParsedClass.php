<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport\Mapping;

/** What a legacy class string means. */
final readonly class ParsedClass
{
    private function __construct(
        public ?int $grade,
        public ?string $stream,
        public bool $isGraduationMarker,
    ) {}

    public static function classOf(int $grade, ?string $stream): self
    {
        return new self($grade, $stream, false);
    }

    /** Legacy wrote "Completed" into the class column when a pupil left the section. */
    public static function graduated(): self
    {
        return new self(null, null, true);
    }

    public function name(): string
    {
        return trim("Grade {$this->grade} {$this->stream}");
    }

    public function key(): string
    {
        return $this->grade.'|'.($this->stream ?? '');
    }
}
