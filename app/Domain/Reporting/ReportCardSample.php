<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Examination\Enums\ExamType;
use App\Domain\Reporting\Enums\ReportLayout;
use App\Domain\Reporting\Enums\ReportTemplate;
use App\Domain\Reporting\Services\ReportCardBuilder;
use App\Domain\Tenancy\Models\School;

/**
 * A made-up report card, for showing a school what a template looks like
 * before it chooses one.
 *
 * The school's own identity (name, motto, contacts, logo, head teacher,
 * footer) with an invented pupil and invented marks. It reads no pupil, exam
 * or result: a preview must work for a school with nothing marked yet, and
 * must never put a real child's results on a screen nobody asked to see them
 * on. The same shape as a ReportCardBuilder payload, so it prints through the
 * same views, with an absence, strands and every typed field filled in.
 *
 * Its size is set by the Classic template, the roomiest-printing of the three:
 * with a logo, a full address block and a remark, Classic holds about ten
 * subjects on a single-exam card and about six subjects plus four strand rows
 * on a term card before it spills onto a second page. So the sample is eight
 * subjects, and on the term card the first six with three strand rows — one
 * page in every template, so that previews can be compared like for like.
 *
 * The levels are written out, not resolved through the school's grading scale:
 * they illustrate the design, they are not anyone's grade.
 */
class ReportCardSample
{
    private const BANDS = [
        'EE' => 'Exceeding Expectations',
        'ME' => 'Meeting Expectations',
        'AE' => 'Approaching Expectations',
        'BE' => 'Below Expectations',
    ];

    /**
     * Subject, code, [Mid-Term %, level], [End-Term %, level], and for a
     * multi-paper subject its strands as name => [mid raw, end raw, out of].
     * A null mark is an absence. The term card prints the first
     * TERM_CARD_SUBJECTS of these.
     */
    private const SUBJECTS = [
        ['Mathematics', 'MAT', [78, 'ME'], [84, 'EE'], []],
        ['English', 'ENG', [71, 'ME'], [76, 'ME'], ['Reading' => [14, 16, 20], 'Grammar' => [29, 31, 40], 'Composition' => [28, 29, 40]]],
        ['Kiswahili', 'KIS', [64, 'ME'], [69, 'ME'], []],
        ['Integrated Science', 'SCI', [58, 'AE'], [66, 'ME'], []],
        ['Agriculture', 'AGR', [81, 'EE'], [79, 'ME'], []],
        ['Social Studies', 'SST', [null, null], [70, 'ME'], []],
        ['Christian Religious Education', 'CRE', [88, 'EE'], [91, 'EE'], []],
        ['Creative Arts and Sports', 'CAS', [47, 'AE'], [null, null], []],
    ];

    private const TERM_CARD_SUBJECTS = 6;

    /**
     * @return array<string, mixed> a card payload, as ReportCardBuilder builds
     */
    public function card(School $school, ReportTemplate $template, ReportLayout $layout): array
    {
        $school->loadMissing('settings');
        $year = (int) now()->year;

        // Column indexes into a SUBJECTS row: Mid-Term then End-Term on a term
        // card; a single card is the End-Term alone.
        $columns = $layout === ReportLayout::TERM ? [0, 1] : [1];
        $types = [ExamType::MIDTERM, ExamType::ENDTERM];
        $subjects = $layout === ReportLayout::TERM ? array_slice(self::SUBJECTS, 0, self::TERM_CARD_SUBJECTS) : self::SUBJECTS;

        return [
            'layout' => $layout->value,
            'template' => $template->value,
            'school' => [
                'name' => $school->name,
                'motto' => $school->motto,
                'address' => $school->address,
                'phone' => $school->phone,
                'email' => $school->email,
                'logo_path' => $school->logo_path,
            ],
            'class' => [
                'name' => 'Grade 6 Blue',
                'grade' => 'Grade 6',
                'stream' => 'Blue',
                'class_teacher' => 'Class Teacher (sample)',
            ],
            'academic_year' => (string) $year,
            'exam' => ['id' => 0, 'name' => "End-Term Exam, Term 2 {$year}", 'type' => ExamType::ENDTERM->value],
            'columns' => array_map(fn (int $i) => [
                'exam_id' => 0,
                'name' => $types[$i]->label(),
                'type' => $types[$i]->value,
                'type_label' => $types[$i]->label(),
                'available' => true,
            ], $columns),
            'term' => [
                'name' => 'Term 2',
                'closing_date' => "{$year}-08-07",
                'opening_date' => "{$year}-08-31",
                'next_term_feeding_fee' => 3500.0,
            ],
            'head_teacher' => $school->setting('report_card.head_teacher_name'),
            'footer' => $school->setting('report_card.footer', ReportCardBuilder::DEFAULT_FOOTER),
            'generated_at' => now()->toIso8601String(),
            'student' => [
                'student_id' => 0,
                'enrolment_id' => 0,
                'assessment_no' => 'SAMPLE-0001',
                'name' => 'Sample Pupil',
            ],
            'rows' => $this->rows($subjects, $columns, $layout),
            'totals' => array_map(fn (int $i) => $this->total($subjects, $i), $columns),
            'entry' => [
                'class_teacher_remarks' => 'A steady, hard-working term with real progress in Mathematics and Integrated Science. '
                    .'More reading at home would help Kiswahili catch up.',
                'fee_balance' => 12500.0,
            ],
        ];
    }

    /**
     * @param  list<array<int, mixed>>  $subjects
     * @param  list<int>  $columns
     * @return list<array<string, mixed>>
     */
    private function rows(array $subjects, array $columns, ReportLayout $layout): array
    {
        $rows = [];

        foreach ($subjects as [$name, $code, $mid, $end, $papers]) {
            $marks = [$mid, $end];

            // Strands print on the term card only, as ReportCardBuilder::rows().
            if ($layout === ReportLayout::TERM) {
                foreach ($papers as $paper => $raw) {
                    $rows[] = [
                        'kind' => 'paper',
                        'name' => $paper,
                        'subject_name' => $name,
                        'counts_toward_total' => false,
                        'cells' => array_map(fn (int $i) => [
                            'score' => (float) $raw[$i],
                            'max_marks' => (float) $raw[2],
                            'is_absent' => false,
                            'band' => null,
                            'band_label' => null,
                        ], $columns),
                    ];
                }
            }

            $rows[] = [
                'kind' => 'subject',
                'name' => $name,
                'code' => $code,
                'counts_toward_total' => true,
                'cells' => array_map(fn (int $i) => [
                    'score' => $marks[$i][0] === null ? null : (float) $marks[$i][0],
                    'max_marks' => 100.0,
                    'is_absent' => $marks[$i][0] === null,
                    'band' => $marks[$i][1],
                    'band_label' => $marks[$i][1] === null ? null : self::BANDS[$marks[$i][1]],
                ], $columns),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<int, mixed>>  $subjects
     * @return array<string, mixed>
     */
    private function total(array $subjects, int $column): array
    {
        $scores = array_values(array_filter(
            array_map(fn (array $subject) => $subject[2 + $column][0], $subjects),
            fn (?int $score) => $score !== null,
        ));
        $total = (float) array_sum($scores);

        return [
            'total_marks' => $total,
            'mean_marks' => round($total / count($scores), 2),
            'mean_band' => 'ME',
            'mean_band_label' => self::BANDS['ME'],
            // False for a column with an absence in it.
            'is_complete' => count($scores) === count($subjects),
        ];
    }
}
