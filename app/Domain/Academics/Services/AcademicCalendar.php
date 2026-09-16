<?php

declare(strict_types=1);

namespace App\Domain\Academics\Services;

use App\Domain\Academics\Exceptions\SetupConflict;
use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\Term;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Examination\Models\Exam;
use App\Domain\People\Models\ClassTeacherAssignment;
use App\Domain\People\Models\ExaminerAssignment;
use App\Domain\People\Models\StudentEnrolment;
use Illuminate\Support\Facades\DB;

/**
 * Academic years and their terms. Legacy had no calendar at all: an exam's year
 * was whatever YEAR(date_created) said, and term dates were blank lines printed
 * on report cards to be filled in by hand.
 */
class AcademicCalendar
{
    /**
     * @param  array{name: string, starts_on: string, ends_on: string, make_current?: bool}  $year
     * @param  list<array{term_number: int, name: string, starts_on?: ?string, ends_on?: ?string, feeding_fee?: float|string|null}>  $terms
     */
    public function createYear(int $schoolId, array $year, array $terms): AcademicYear
    {
        return DB::transaction(function () use ($schoolId, $year, $terms) {
            $created = AcademicYear::create([
                'school_id' => $schoolId,
                'name' => $year['name'],
                'starts_on' => $year['starts_on'],
                'ends_on' => $year['ends_on'],
                'is_current' => false,
            ]);

            // A school year in Kenya has three terms; that is the default when the
            // admin does not lay them out.
            $terms = $terms !== [] ? $terms : array_map(
                fn (int $n) => ['term_number' => $n, 'name' => "Term {$n}"],
                [1, 2, 3],
            );

            foreach ($terms as $term) {
                Term::create([
                    'school_id' => $schoolId,
                    'academic_year_id' => $created->id,
                    'term_number' => $term['term_number'],
                    'name' => $term['name'],
                    'starts_on' => $term['starts_on'] ?? null,
                    'ends_on' => $term['ends_on'] ?? null,
                    'feeding_fee' => $term['feeding_fee'] ?? null,
                    'is_current' => false,
                ]);
            }

            if ($year['make_current'] ?? false) {
                $this->makeYearCurrent($created);
            }

            return $created->fresh('terms');
        });
    }

    /** Exactly one current year per school. */
    public function makeYearCurrent(AcademicYear $year): AcademicYear
    {
        DB::transaction(function () use ($year): void {
            AcademicYear::query()->where('school_id', $year->school_id)->whereKeyNot($year->id)->update(['is_current' => false]);
            $year->forceFill(['is_current' => true])->save();

            // The current term must belong to the current year.
            Term::query()->where('school_id', $year->school_id)->where('academic_year_id', '!=', $year->id)->update(['is_current' => false]);
        });

        return $year->fresh('terms');
    }

    /** Exactly one current term per school, and its year becomes current too. */
    public function makeTermCurrent(Term $term): Term
    {
        DB::transaction(function () use ($term): void {
            Term::query()->where('school_id', $term->school_id)->whereKeyNot($term->id)->update(['is_current' => false]);
            $term->forceFill(['is_current' => true])->save();

            $year = AcademicYear::query()->findOrFail($term->academic_year_id);
            if (! $year->is_current) {
                AcademicYear::query()->where('school_id', $term->school_id)->whereKeyNot($year->id)->update(['is_current' => false]);
                $year->forceFill(['is_current' => true])->save();
            }
        });

        return $term->fresh('academicYear');
    }

    /** Only a year nothing has been recorded against can be deleted. */
    public function deleteYear(AcademicYear $year): void
    {
        $uses = array_filter([
            'exams' => Exam::query()->where('academic_year_id', $year->id)->exists(),
            'pupil enrolments' => StudentEnrolment::query()->where('academic_year_id', $year->id)->exists(),
            'class teacher assignments' => ClassTeacherAssignment::query()->where('academic_year_id', $year->id)->exists(),
            'examiner assignments' => ExaminerAssignment::query()->where('academic_year_id', $year->id)->exists(),
            'subject versions' => LevelSubject::query()->where('effective_from_year_id', $year->id)->exists(),
        ]);

        if ($uses !== []) {
            throw SetupConflict::cannotDelete(
                "Academic year {$year->name}",
                'it already has '.implode(', ', array_keys($uses)),
                'Years with records are kept for history.',
            );
        }

        DB::transaction(function () use ($year): void {
            Term::query()->where('academic_year_id', $year->id)->delete();
            $year->delete();
        });
    }
}
