<?php

declare(strict_types=1);

namespace App\Domain\People\Services;

use App\Domain\Academics\Exceptions\SetupConflict;
use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Examination\Models\ExamStudentResult;
use App\Domain\Examination\Models\ExamSubjectScore;
use App\Domain\People\Models\Student;
use App\Domain\People\Models\StudentEnrolment;
use App\Domain\Reporting\Models\ReportCardEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pupils and their year-by-year enrolment. Legacy kept a single mutable
 * students.class string (plus student_classes history in some deployments), so
 * moving a pupil rewrote which class their past results appeared under.
 * Enrolments pin each year's class; results keep the class they were earned in.
 */
class StudentService
{
    /** @param  array<string, mixed>  $data */
    public function admit(int $schoolId, array $data, int $classId, int $academicYearId): Student
    {
        return DB::transaction(function () use ($schoolId, $data, $classId, $academicYearId) {
            $student = Student::create($this->studentAttributes($data) + ['school_id' => $schoolId, 'status' => 'active']);

            StudentEnrolment::create([
                'school_id' => $schoolId,
                'student_id' => $student->id,
                'academic_year_id' => $academicYearId,
                'class_id' => $classId,
                'status' => 'active',
                'started_on' => $data['started_on'] ?? now()->toDateString(),
            ]);

            return $student->fresh();
        });
    }

    /** Moves a pupil to another class for a year, enrolling them if they were not. */
    public function move(Student $student, SchoolClass $class, int $academicYearId): StudentEnrolment
    {
        if (! $class->is_active) {
            throw ValidationException::withMessages(['class_id' => 'That class is inactive.']);
        }

        return DB::transaction(function () use ($student, $class, $academicYearId) {
            $enrolment = StudentEnrolment::query()->firstOrNew(['student_id' => $student->id, 'academic_year_id' => $academicYearId]);
            $enrolment->fill([
                'school_id' => $student->school_id,
                'class_id' => $class->id,
                'status' => 'active',
                'started_on' => $enrolment->started_on ?? now()->toDateString(),
                'ended_on' => null,
            ])->save();

            if ($student->status !== 'active') {
                $student->forceFill(['status' => 'active'])->save();
            }

            return $enrolment;
        });
    }

    /**
     * A pupil leaving (transferred, graduated, inactive) or returning. Leaving
     * closes the current year's enrolment; their results stay.
     */
    public function changeStatus(Student $student, string $status, ?string $endedOn = null): Student
    {
        DB::transaction(function () use ($student, $status, $endedOn): void {
            $student->forceFill(['status' => $status])->save();

            $currentYearId = AcademicYear::query()->where('is_current', true)->value('id');
            if ($currentYearId === null) {
                return;
            }

            $enrolment = StudentEnrolment::query()->where('student_id', $student->id)->where('academic_year_id', $currentYearId)->first();

            if ($enrolment !== null && $status !== 'active') {
                $enrolment->forceFill(['status' => 'left', 'ended_on' => $endedOn ?? now()->toDateString()])->save();
            } elseif ($enrolment !== null && $status === 'active' && $enrolment->status === 'left') {
                $enrolment->forceFill(['status' => 'active', 'ended_on' => null])->save();
            }
        });

        return $student->fresh();
    }

    /** Only a pupil added by mistake — nothing recorded against them — is deleted. */
    public function delete(Student $student): void
    {
        $recorded = ExamSubjectScore::query()->where('student_id', $student->id)->exists()
            || ExamStudentResult::query()->where('student_id', $student->id)->exists()
            || ReportCardEntry::query()->where('student_id', $student->id)->exists();

        if ($recorded) {
            throw SetupConflict::cannotDelete($student->full_name, 'marks or report card entries are recorded for them', 'Mark them transferred or inactive instead.');
        }

        DB::transaction(function () use ($student): void {
            StudentEnrolment::query()->where('student_id', $student->id)->delete();
            $student->forceDelete();
        });
    }

    /** @param  array<string, mixed>  $data */
    public function studentAttributes(array $data): array
    {
        return collect($data)->only([
            'admission_no', 'upi', 'first_name', 'middle_name', 'last_name', 'gender', 'date_of_birth', 'guardian_name', 'guardian_phone',
        ])->all();
    }
}
