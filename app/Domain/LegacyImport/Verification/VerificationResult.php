<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport\Verification;

final class VerificationResult
{
    /** @var array<string, array{checked: int, failures: list<string>}> */
    public array $gates = [];

    /** @var array<string, array<string, int>> section => category => count */
    public array $findings = [];

    /** @var array<string, list<string>> section => examples */
    public array $examples = [];

    public function check(string $gate, bool $ok, string $failure): void
    {
        $this->gates[$gate] ??= ['checked' => 0, 'failures' => []];
        $this->gates[$gate]['checked']++;

        if (! $ok) {
            $this->gates[$gate]['failures'][] = $failure;
        }
    }

    public function find(string $section, string $category, ?string $example = null): void
    {
        $this->findings[$section][$category] = ($this->findings[$section][$category] ?? 0) + 1;

        if ($example !== null && count($this->examples["{$section}: {$category}"] ?? []) < 25) {
            $this->examples["{$section}: {$category}"][] = $example;
        }
    }

    public function passed(): bool
    {
        foreach ($this->gates as $gate) {
            if ($gate['failures'] !== []) {
                return false;
            }
        }

        return $this->gates !== [];
    }

    public function toMarkdown(string $title): string
    {
        $out = "# {$title}\n\n**".($this->passed() ? 'PASSED' : 'FAILED')."** — every gate must be exact.\n\n## Gates\n\n| Gate | Checked | Failures |\n|---|---|---|\n";

        foreach ($this->gates as $name => $gate) {
            $out .= "| {$name} | {$gate['checked']} | ".count($gate['failures'])." |\n";
        }

        foreach ($this->gates as $name => $gate) {
            if ($gate['failures'] !== []) {
                $out .= "\n### {$name} failures\n\n".implode("\n", array_map(fn ($f) => "- {$f}", array_slice($gate['failures'], 0, 100)))."\n";
            }
        }

        $out .= "\n## Findings (not gates)\n\nLegacy totals, positions and combined marks were derived values, often stale or computed by since-fixed rules. They are compared, not enforced.\n";

        foreach ($this->findings as $section => $categories) {
            $out .= "\n### {$section}\n\n";
            foreach ($categories as $category => $count) {
                $out .= "- {$category}: **{$count}**\n";
            }
        }

        if ($this->examples !== []) {
            $out .= "\n## Examples\n";
            foreach ($this->examples as $key => $examples) {
                $out .= "\n### {$key}\n\n".implode("\n", array_map(fn ($e) => "- {$e}", $examples))."\n";
            }
        }

        return $out;
    }
}
