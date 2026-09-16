<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport\Review;

use App\Domain\LegacyImport\Models\LegacyDecision;
use Illuminate\Support\Collection;

/**
 * Questions only the school can answer, asked once.
 *
 * The importer proposes; the school answers through `legacy:review`; every later
 * run reads the answer. If a later run's proposal differs from the one that was
 * answered — the legacy data changed underneath it — the item re-opens, because
 * the old answer was to a different question.
 *
 * Proposals are buffered and written by flush() after the import transaction
 * ends, so a dry run or blocked run still leaves the questions for the school.
 */
class DecisionBook
{
    /** @var array<string, LegacyDecision> existing decisions, keyed kind|key */
    private ?array $existing = null;

    /** @var array<string, array{kind: string, key: string, proposal: array, checksum: string, auto: ?string}> */
    private array $proposals = [];

    public function __construct(private readonly int $schoolId) {}

    /** Record a question for this run and return its current answer. */
    public function propose(string $kind, string $key, array $proposal, ?string $autoDecision = null): string
    {
        $checksum = hash('sha256', json_encode($proposal, JSON_UNESCAPED_UNICODE));
        $this->proposals["{$kind}|{$key}"] = compact('kind', 'key', 'proposal', 'checksum') + ['auto' => $autoDecision];

        return $this->answer($kind, $key);
    }

    /** The answer for a question already proposed this run. */
    public function answer(string $kind, string $key): ?string
    {
        $proposal = $this->proposals["{$kind}|{$key}"] ?? null;

        if ($proposal === null) {
            return ($this->existing()["{$kind}|{$key}"] ?? null)?->decision;
        }

        $existing = $this->existing()["{$kind}|{$key}"] ?? null;

        return $existing !== null && $existing->proposal_checksum === $proposal['checksum']
            ? $existing->decision
            : ($proposal['auto'] ?? LegacyDecision::PENDING);
    }

    public function payload(string $kind, string $key): ?array
    {
        return ($this->existing()["{$kind}|{$key}"] ?? null)?->payload;
    }

    public function pendingCount(): int
    {
        $pending = 0;

        foreach ($this->proposals as $id => $p) {
            if ($this->answer($p['kind'], $p['key']) === LegacyDecision::PENDING) {
                $pending++;
            }
        }

        return $pending;
    }

    /** Persist this run's proposals. Answers the school gave to unchanged questions are kept. */
    public function flush(): void
    {
        $existing = $this->existing();

        foreach ($this->proposals as $id => $p) {
            $decision = $existing[$id] ?? null;

            if ($decision === null) {
                LegacyDecision::create([
                    'school_id' => $this->schoolId,
                    'kind' => $p['kind'],
                    'subject_key' => $p['key'],
                    'proposal' => $p['proposal'],
                    'proposal_checksum' => $p['checksum'],
                    'decision' => $p['auto'] ?? LegacyDecision::PENDING,
                    'decided_by' => $p['auto'] === null ? null : 'importer (automatic)',
                    'decided_at' => $p['auto'] === null ? null : now(),
                ]);
            } elseif ($decision->proposal_checksum !== $p['checksum']) {
                $decision->fill([
                    'proposal' => $p['proposal'],
                    'proposal_checksum' => $p['checksum'],
                    'decision' => $p['auto'] ?? LegacyDecision::PENDING,
                    'payload' => null,
                    'note' => trim(($decision->note ? $decision->note."\n" : '').'Re-opened '.now()->toDateString().': the legacy data behind this question changed.'),
                    'decided_by' => $p['auto'] === null ? null : 'importer (automatic)',
                    'decided_at' => $p['auto'] === null ? null : now(),
                ])->save();
            }
        }

        $this->existing = null;
    }

    /** @return Collection<int, LegacyDecision> */
    public function pending(): Collection
    {
        return LegacyDecision::query()->where('decision', LegacyDecision::PENDING)->orderBy('kind')->orderBy('subject_key')->get();
    }

    /** @return array<string, LegacyDecision> */
    private function existing(): array
    {
        return $this->existing ??= LegacyDecision::query()->get()
            ->keyBy(fn (LegacyDecision $d) => $d->kind.'|'.$d->subject_key)
            ->all();
    }
}
