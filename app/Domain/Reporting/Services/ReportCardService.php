<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Examination\Enums\ExamStatus;
use App\Domain\Examination\Models\Exam;
use App\Domain\People\Models\StudentEnrolment;
use App\Domain\People\Models\User;
use App\Domain\Reporting\Enums\ReportLayout;
use App\Domain\Reporting\Enums\ReportTemplate;
use App\Domain\Reporting\Exceptions\ReportCardUnavailable;
use App\Domain\Reporting\Models\ReportCardSnapshot;
use Illuminate\Support\Facades\Log;

/**
 * The one way report cards are read.
 *
 * For a published exam, cards come from the snapshot frozen at publication:
 * what went home to parents must not change afterwards, whatever later happens
 * to grading scales, school details or marks. Anything else is built live.
 *
 * Every card carries `is_snapshot` so a client can tell the two apart.
 */
class ReportCardService
{
    public function __construct(private readonly ReportCardBuilder $builder) {}

    /** @return array<string, mixed>|null */
    public function forEnrolment(Exam $exam, StudentEnrolment $enrolment, ReportLayout $layout): ?array
    {
        if ($this->isFrozen($exam)) {
            $snapshot = ReportCardSnapshot::query()
                ->where('exam_id', $exam->id)
                ->where('student_id', $enrolment->student_id)
                ->where('format', 'json')
                ->first();

            if ($snapshot !== null) {
                return $this->fromSnapshot($snapshot, $exam, $layout);
            }
        }

        $card = $this->builder->forEnrolment($exam, $enrolment, $layout);

        return $card === null ? null : $card + ['is_snapshot' => false];
    }

    /** @return array<int, array<string, mixed>> keyed by enrolment id */
    public function forClass(Exam $exam, SchoolClass $class, ReportLayout $layout): array
    {
        $cards = [];

        if ($this->isFrozen($exam)) {
            $snapshots = ReportCardSnapshot::query()
                ->where('exam_id', $exam->id)
                ->where('format', 'json')
                ->whereHas('enrolment', fn ($q) => $q->where('class_id', $class->id))
                ->get();

            foreach ($snapshots as $snapshot) {
                $card = $this->fromSnapshot($snapshot, $exam, $layout);

                if ($card !== null) {
                    $cards[$snapshot->enrolment_id] = $card;
                }
            }

            if ($cards !== []) {
                return collect($cards)->sortBy(fn (array $c) => $c['student']['name'])->all();
            }
        }

        return array_map(
            fn (array $card) => $card + ['is_snapshot' => false],
            $this->builder->forClass($exam, $class, $layout),
        );
    }

    /**
     * Freeze every student's cards for an exam being published. Idempotent: one
     * row per student. Repeating publication replaces a card only if its content
     * changed (a card is not re-dated just because it was frozen again), and
     * removes the cards of students no longer in the exam.
     *
     * A student's card keeps the template (design) it was first frozen with,
     * whatever the school has chosen since: see withIssuedTemplate().
     *
     * @return int students snapshotted
     */
    public function snapshot(Exam $exam, ?User $publishedBy = null): int
    {
        $count = 0;
        $students = [];
        $existing = ReportCardSnapshot::query()->where('exam_id', $exam->id)->where('format', 'json')->get()->keyBy('student_id');

        foreach ($exam->classes()->with(['grade', 'stream'])->get() as $class) {
            $single = $this->builder->forClass($exam, $class, ReportLayout::SINGLE);

            try {
                $term = $this->builder->forClass($exam, $class, ReportLayout::TERM);
            } catch (ReportCardUnavailable) {
                $term = [];   // not a Mid-Term/End-Term exam: no term card to freeze
            }

            foreach ($single as $enrolmentId => $card) {
                $payload = ['single' => $card, 'term' => $term[$enrolmentId] ?? null];
                $studentId = $card['student']['student_id'];
                $students[] = $studentId;
                $count++;

                $previous = $existing->get($studentId);

                if ($previous !== null) {
                    $payload = self::withIssuedTemplate($payload, $previous->payload ?? []);
                }

                if ($previous !== null && $previous->isIntact() && (int) $previous->enrolment_id === (int) $enrolmentId
                    && self::undated($previous->payload ?? []) === self::undated($payload)) {
                    continue;
                }

                ReportCardSnapshot::query()->updateOrCreate(
                    ['exam_id' => $exam->id, 'student_id' => $studentId, 'format' => 'json'],
                    [
                        'school_id' => $exam->school_id,
                        'enrolment_id' => $enrolmentId,
                        'payload' => $payload,
                        'checksum' => ReportCardSnapshot::checksumFor($payload),
                        'generated_at' => now(),
                        'published_by' => $publishedBy?->id,
                    ],
                );
            }
        }

        ReportCardSnapshot::query()->where('exam_id', $exam->id)->whereNotIn('student_id', $students)->delete();

        return $count;
    }

    /**
     * A fresh payload, in the template the student's card was issued with.
     *
     * The template is part of what was issued, so once a snapshot exists it is
     * fixed: changing the school's setting restyles cards not yet published,
     * never one that has gone home. Nothing leaves PUBLISHED but ARCHIVED
     * (ExamStatus::allowedTransitions), so the only caller that freezes an
     * exam twice is the legacy importer's finalise step — and rule 17 needs
     * that second run to change nothing.
     *
     * A card frozen before templates existed has no `template` key, and keeps
     * having none: adding one would rewrite (and re-date) every card issued
     * until now the first time the importer re-ran. Reads supply the default.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $issued
     * @return array<string, mixed>
     */
    private static function withIssuedTemplate(array $payload, array $issued): array
    {
        // Both layouts are frozen together, so the single card (always present)
        // speaks for the pair — including a term card that only now exists.
        $template = $issued['single']['template'] ?? $issued['term']['template'] ?? null;

        foreach (['single', 'term'] as $layout) {
            if (! is_array($payload[$layout] ?? null)) {
                continue;
            }

            if ($template === null) {
                unset($payload[$layout]['template']);
            } else {
                $payload[$layout]['template'] = $template;
            }
        }

        return $payload;
    }

    /** A payload's checksum ignoring when it was generated. */
    private static function undated(array $payload): string
    {
        foreach (['single', 'term'] as $layout) {
            if (is_array($payload[$layout] ?? null)) {
                unset($payload[$layout]['generated_at']);
            }
        }

        return ReportCardSnapshot::checksumFor($payload);
    }

    private function isFrozen(Exam $exam): bool
    {
        return in_array($exam->status, [ExamStatus::PUBLISHED, ExamStatus::ARCHIVED], true);
    }

    /** @return array<string, mixed>|null */
    private function fromSnapshot(ReportCardSnapshot $snapshot, Exam $exam, ReportLayout $layout): ?array
    {
        if (! $snapshot->isIntact()) {
            // Do not silently serve content that no longer matches what was
            // issued. Surfaced to the client and logged loudly for investigation.
            Log::critical('Report card snapshot checksum mismatch', [
                'snapshot_id' => $snapshot->id, 'exam_id' => $exam->id, 'student_id' => $snapshot->student_id,
            ]);
        }

        $card = $snapshot->payload[$layout->value] ?? null;

        if ($card === null) {
            if ($layout === ReportLayout::TERM) {
                throw ReportCardUnavailable::notATermExam($exam);
            }

            return null;
        }

        // Cards frozen before 2026_09_21_000100 call the number admission_no.
        // Renamed on the way out, never in the stored payload: the checksum is
        // over what was issued, and rewriting it would make every card look
        // tampered with.
        if (is_array($card['student'] ?? null) && ! array_key_exists('assessment_no', $card['student']) && array_key_exists('admission_no', $card['student'])) {
            $card['student']['assessment_no'] = $card['student']['admission_no'];
            unset($card['student']['admission_no']);
        }

        // Cards frozen before templates existed name none; they were issued in
        // what is now Classic. Supplied on the way out for the same reason.
        $card['template'] ??= ReportTemplate::CLASSIC->value;

        return $card + [
            'is_snapshot' => true,
            'snapshot_intact' => $snapshot->isIntact(),
            'published_at' => $snapshot->generated_at?->toIso8601String(),
        ];
    }
}
