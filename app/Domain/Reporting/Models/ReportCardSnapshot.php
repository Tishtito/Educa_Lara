<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Models;

use App\Domain\Examination\Models\Exam;
use App\Domain\People\Models\Student;
use App\Domain\People\Models\StudentEnrolment;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The frozen content of a student's cards, taken when the exam is published.
 *
 * Once a card has gone home it must never change, even if the grading scale is
 * edited, the school renames itself, or a mark is corrected later. Reads of a
 * published exam's cards come from here, not from live data. The checksum is
 * over the canonical JSON payload, so tampering or corruption is detectable.
 */
class ReportCardSnapshot extends Model
{
    use BelongsToSchool;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrolment::class, 'enrolment_id');
    }

    /**
     * SHA-256 over a canonical encoding of the payload.
     *
     * Not over json_encode($payload) directly: the payload column is a native
     * MySQL JSON column, which stores objects with its own key order and writes
     * 65.0 back as 65. A checksum of the array as sent would never match the
     * array read back, and every issued card would look tampered with.
     */
    public static function checksumFor(array $payload): string
    {
        return hash('sha256', json_encode(self::canonical($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Keys sorted at every level; whole-number floats as integers. List order is kept. */
    private static function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map(self::canonical(...), $value);
        }

        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < 1e15) {
            return (int) $value;
        }

        return $value;
    }

    public function isIntact(): bool
    {
        return hash_equals($this->checksum, self::checksumFor($this->payload ?? []));
    }
}
