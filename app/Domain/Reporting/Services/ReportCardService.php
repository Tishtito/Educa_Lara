<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Examination\Enums\ExamStatus;
use App\Domain\Examination\Models\Exam;
use App\Domain\People\Models\StudentEnrolment;
use App\Domain\People\Models\User;
use App\Domain\Reporting\Enums\ReportLayout;
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
     * row per student, replaced if publication is repeated.
     *
     * @return int students snapshotted
     */
    public function snapshot(Exam $exam, ?User $publishedBy = null): int
    {
        $count = 0;

        foreach ($exam->classes()->with(['grade', 'stream'])->get() as $class) {
            $single = $this->builder->forClass($exam, $class, ReportLayout::SINGLE);

            try {
                $term = $this->builder->forClass($exam, $class, ReportLayout::TERM);
            } catch (ReportCardUnavailable) {
                $term = [];   // not a Mid-Term/End-Term exam: no term card to freeze
            }

            foreach ($single as $enrolmentId => $card) {
                $payload = ['single' => $card, 'term' => $term[$enrolmentId] ?? null];

                ReportCardSnapshot::query()->updateOrCreate(
                    ['exam_id' => $exam->id, 'student_id' => $card['student']['student_id'], 'format' => 'json'],
                    [
                        'school_id' => $exam->school_id,
                        'enrolment_id' => $enrolmentId,
                        'payload' => $payload,
                        'checksum' => ReportCardSnapshot::checksumFor($payload),
                        'generated_at' => now(),
                        'published_by' => $publishedBy?->id,
                    ],
                );

                $count++;
            }
        }

        return $count;
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

        return $card + [
            'is_snapshot' => true,
            'snapshot_intact' => $snapshot->isIntact(),
            'published_at' => $snapshot->generated_at?->toIso8601String(),
        ];
    }
}
