<?php

declare(strict_types=1);

namespace App\Domain\People\Services;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\People\Models\Student;
use App\Domain\People\Models\StudentEnrolment;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Admitting many pupils from a spreadsheet. Legacy had no import at all: class
 * teachers typed pupils in one at a time, name only. Every row is checked
 * before anything is written, and nothing is written unless every row is
 * valid, so a half-imported class never happens.
 */
class StudentImportService
{
    public const MAX_ROWS = 2000;

    /**
     * @param  list<array<string, mixed>>  $rows  Parsed spreadsheet rows, keyed by column name.
     * @return array{total: int, valid: int, errors: list<array{row: int, field: string, message: string}>, preview: list<array<string, mixed>>, created: int, committed: bool}
     */
    public function import(int $schoolId, int $academicYearId, array $rows, bool $dryRun): array
    {
        $classes = SchoolClass::query()->where('is_active', true)->get()
            ->keyBy(fn (SchoolClass $c) => $this->key($c->name));

        $existing = Student::query()->withTrashed()
            ->whereIn('assessment_no', collect($rows)->pluck('assessment_no')->filter()->map(fn ($v) => trim((string) $v)))
            ->pluck('assessment_no')
            ->map(fn ($v) => Str::lower($v))
            ->flip();

        $errors = [];
        $clean = [];
        $seen = [];

        foreach (array_values($rows) as $index => $row) {
            $line = $index + 1;
            $fail = function (string $field, string $message) use (&$errors, $line): void {
                $errors[] = ['row' => $line, 'field' => $field, 'message' => $message];
            };

            $row = array_map(fn ($v) => is_string($v) ? trim(preg_replace('/\s+/', ' ', $v) ?? '') : $v, $row);
            [$first, $middle, $last] = $this->names($row);

            $assessment = (string) ($row['assessment_no'] ?? '');
            if ($assessment === '') {
                $fail('assessment_no', 'Assessment number is missing. The column must be headed “Assessment No”.');
            } elseif (mb_strlen($assessment) > 40) {
                $fail('assessment_no', 'Assessment number is longer than 40 characters.');
            } elseif (isset($existing[Str::lower($assessment)])) {
                $fail('assessment_no', "A pupil with assessment number {$assessment} already exists.");
            } elseif (isset($seen[Str::lower($assessment)])) {
                $fail('assessment_no', "Assessment number {$assessment} appears twice in the file (row {$seen[Str::lower($assessment)]}).");
            } else {
                $seen[Str::lower($assessment)] = $line;
            }

            if ($first === null || $last === null) {
                $fail('name', 'Give at least a first and last name.');
            }

            $class = $classes->get($this->key((string) ($row['class'] ?? '')));
            if ($class === null) {
                $fail('class', filled($row['class'] ?? null) ? "No active class is called “{$row['class']}”." : 'Class is missing.');
            }

            $gender = $this->gender($row['gender'] ?? null);
            if ($gender === false) {
                $fail('gender', 'Gender should be male, female or blank.');
            }

            $dob = $this->date($row['date_of_birth'] ?? null);
            if ($dob === false) {
                $fail('date_of_birth', 'Date of birth should look like 2016-03-21 or 21/03/2016.');
            }

            $status = $this->status($row['status'] ?? null);
            if ($status === false) {
                $fail('status', 'Status should be active, transferred, graduated, inactive or blank.');
            }

            // A pupil can be enrolled from a future date (next term's intake), unlike a birth date.
            $joined = $this->date($row['started_on'] ?? null, allowFuture: true);
            if ($joined === false) {
                $fail('started_on', 'The date joined should look like 2026-01-06 or 06/01/2026.');
            }

            $clean[] = [
                'assessment_no' => $assessment,
                'first_name' => $first,
                'middle_name' => $middle,
                'last_name' => $last,
                'gender' => $gender ?: null,
                'date_of_birth' => $dob ?: null,
                'guardian_name' => ($row['guardian_name'] ?? null) ?: null,
                'guardian_phone' => ($row['guardian_phone'] ?? null) ?: null,
                'upi' => ($row['upi'] ?? null) ?: null,
                'status' => $status ?: 'active',
                'started_on' => $joined ?: null,
                'class_id' => $class?->id,
                'class_name' => $class?->name,
            ];
        }

        $invalidRows = collect($errors)->pluck('row')->unique()->count();
        $result = [
            'total' => count($rows),
            'valid' => count($rows) - $invalidRows,
            'errors' => $errors,
            'preview' => array_slice($clean, 0, 25),
            'created' => 0,
            'committed' => false,
        ];

        if ($dryRun || $errors !== [] || $rows === []) {
            return $result;
        }

        DB::transaction(function () use ($clean, $schoolId, $academicYearId): void {
            foreach ($clean as $row) {
                $student = Student::create(collect($row)->except(['class_id', 'class_name', 'started_on'])->all() + ['school_id' => $schoolId]);
                StudentEnrolment::create([
                    'school_id' => $schoolId,
                    'student_id' => $student->id,
                    'academic_year_id' => $academicYearId,
                    'class_id' => $row['class_id'],
                    // A pupil imported as anything but active has already left that class.
                    'status' => $row['status'] === 'active' ? 'active' : 'left',
                    'started_on' => $row['started_on'] ?? now()->toDateString(),
                ]);
            }
        });

        return ['created' => count($clean), 'committed' => true] + $result;
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string} */
    private function names(array $row): array
    {
        if (filled($row['first_name'] ?? null) || filled($row['last_name'] ?? null)) {
            return [($row['first_name'] ?? null) ?: null, ($row['middle_name'] ?? null) ?: null, ($row['last_name'] ?? null) ?: null];
        }

        // A single "name" column, as legacy stored it: first word, last word, the rest between.
        $parts = array_values(array_filter(explode(' ', (string) ($row['name'] ?? ''))));
        if (count($parts) < 2) {
            return [$parts[0] ?? null, null, null];
        }

        return [$parts[0], count($parts) > 2 ? implode(' ', array_slice($parts, 1, -1)) : null, $parts[count($parts) - 1]];
    }

    private function key(string $className): string
    {
        return Str::lower(preg_replace('/[\s_-]+/', ' ', trim($className)) ?? '');
    }

    private function gender(mixed $value): string|false|null
    {
        $v = Str::lower(trim((string) $value));

        return match (true) {
            $v === '' => null,
            in_array($v, ['m', 'male', 'boy'], true) => 'male',
            in_array($v, ['f', 'female', 'girl'], true) => 'female',
            $v === 'other' => 'other',
            default => false,
        };
    }

    /** The pupil's status as the school wrote it. False when it is a word we do not know. */
    private function status(mixed $value): string|false|null
    {
        $v = Str::lower(trim((string) $value));

        return match (true) {
            $v === '' => null,
            in_array($v, ['active', 'current', 'continuing'], true) => 'active',
            in_array($v, ['transferred', 'transfer', 'left'], true) => 'transferred',
            in_array($v, ['graduated', 'completed'], true) => 'graduated',
            in_array($v, ['inactive', 'dormant'], true) => 'inactive',
            default => false,
        };
    }

    private function date(mixed $value, bool $allowFuture = false): string|false|null
    {
        $v = trim((string) $value);
        if ($v === '') {
            return null;
        }

        foreach (['Y-m-d', 'd/m/Y', 'j/n/Y', 'd-m-Y', 'j-n-Y'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $v);
            } catch (InvalidFormatException) {
                // Carbon throws rather than returning false when the text does not fit.
                continue;
            }
            // Round-tripping rejects overflow such as 31/02/2016 becoming 2 March.
            if ($date !== null && $date->format($format) === $v && ($allowFuture || $date->isPast())) {
                return $date->toDateString();
            }
        }

        return false;
    }
}
