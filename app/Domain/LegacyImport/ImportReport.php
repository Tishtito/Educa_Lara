<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport;

/**
 * What a run did, step by step, as counters and notes. Written to the console and
 * to a Markdown file under storage/app/private/legacy/{school}/.
 */
final class ImportReport
{
    /** @var array<string, array<string, int>> step => counter => value */
    public array $counters = [];

    /** @var array<string, list<string>> step => notes */
    public array $notes = [];

    /** @var array<string, float> step => seconds */
    public array $timings = [];

    public function time(string $step, float $seconds): void
    {
        $this->timings[$step] = ($this->timings[$step] ?? 0) + $seconds;
    }

    public function count(string $step, string $counter, int $by = 1): void
    {
        $this->counters[$step][$counter] = ($this->counters[$step][$counter] ?? 0) + $by;
    }

    public function note(string $step, string $note): void
    {
        $this->notes[$step][] = $note;
    }

    public function get(string $step, string $counter): int
    {
        return $this->counters[$step][$counter] ?? 0;
    }

    public function toMarkdown(string $title): string
    {
        $out = "# {$title}\n\n";

        foreach (array_unique(array_merge(array_keys($this->counters), array_keys($this->notes))) as $step) {
            $out .= "## {$step}\n\n";

            foreach ($this->counters[$step] ?? [] as $counter => $value) {
                $out .= "- {$counter}: **{$value}**\n";
            }

            foreach ($this->notes[$step] ?? [] as $note) {
                $out .= "- {$note}\n";
            }

            $out .= "\n";
        }

        if ($this->timings !== []) {
            $out .= "## Timings\n\n";

            foreach ($this->timings as $step => $seconds) {
                $out .= sprintf("- %s: %.1f s\n", $step, $seconds);
            }
        }

        return $out;
    }
}
