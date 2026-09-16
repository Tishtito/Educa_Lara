<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A small, entirely synthetic copy of the school's three legacy databases.
 *
 * Table shapes match the production exports exactly (column names, including
 * `LS/SP`, and the upper database's missing pno). The people are made up. Every
 * data quirk measured in production is reproduced on purpose, so each has a test:
 *
 *   - exam years: lower stores 2025 or 0000, upper and JSS store NULL; only
 *     date_created is reliable
 *   - 2026 results linked to the student's 2025 student_classes row
 *   - two student_classes rows for one student in one year
 *   - class spellings grade_2west / GRADE 3 EAST / grade_3 / 7 / 8_west, the
 *     "Completed" graduation marker, and "6_test"
 *   - a clean Grade 3 → 4 and Grade 6 → 7 cross-section match, and an ambiguous one
 *   - a leaver's class copied into the year they left by the rollover (no results)
 *   - the same teacher as class teacher and examiner; one admin in all three
 *     sections; one username with two different names
 *   - lower 2026 English as RDG + GRM with the old single English still used in
 *     the first 2026 exam; stray LS/SP (zero) and WRI columns
 *   - JSS English/Kiswahili single-mark in Term 1 Mid-Term 2026, Paper 1 + 2 from
 *     Term 1 End-Term; one student with only one paper
 *   - JSS's 8-level scale starting at 1, and lower's broken per-subject bands
 *
 * Tables live in the test database under a per-source prefix (phpunit.xml), are
 * recreated per test, and are outside RefreshDatabase's transaction.
 */
final class LegacyFixture
{
    public const LOWER = 'legacy_lower';

    public const UPPER = 'legacy_upper';

    public const JSS = 'legacy_jss';

    /** Every fixture staff account's legacy password. */
    public const PASSWORD = 'legacy-pass';

    private static ?string $hash = null;

    /** A real bcrypt hash at cost 10, as legacy PHP's password_hash() produced. */
    public static function hash(): string
    {
        return self::$hash ??= password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 10]);
    }

    public static function install(): void
    {
        foreach ([self::LOWER, self::UPPER, self::JSS] as $connection) {
            self::dropAll($connection);
        }

        self::lowerSchema();
        self::upperSchema();
        self::jssSchema();

        self::lowerData();
        self::upperData();
        self::jssData();
    }

    public static function dropAll(string $connection): void
    {
        $schema = Schema::connection($connection);
        $schema->disableForeignKeyConstraints();

        foreach (['exam_mean_scores', 'marks_out_of', 'exam_results', 'examiner_subject_classes', 'student_classes',
            'students', 'exams', 'classes', 'point_boundaries', 'admins', 'class_teachers', 'examiners',
            'users', 'subjects'] as $table) {
            $schema->dropIfExists($table);
        }

        $schema->enableForeignKeyConstraints();
    }

    public static function insert(string $connection, string $table, array $rows): void
    {
        // A multi-row insert needs identical keys on every row; pad with NULL.
        $keys = array_unique(array_merge(...array_map('array_keys', $rows)));
        $rows = array_map(fn (array $row) => array_merge(array_fill_keys($keys, null), $row), $rows);

        DB::connection($connection)->table($table)->insert($rows);
    }

    public static function update(string $connection, string $table, array $where, array $values): void
    {
        DB::connection($connection)->table($table)->where($where)->update($values);
    }

    public static function delete(string $connection, string $table, array $where): void
    {
        DB::connection($connection)->table($table)->where($where)->delete();
    }

    // ------------------------------------------------------------------ schemas

    private static function commonTables(string $connection, bool $withPno, array $examResultColumns, string $totalColumn): void
    {
        $s = Schema::connection($connection);

        $s->create('admins', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name', 100);
            $t->string('username', 50);
            $t->string('password');
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->dateTime('deleted_at')->nullable();
        });
        $s->create('classes', function (Blueprint $t) {
            $t->increments('class_id');
            $t->string('class_name', 50);
            $t->tinyInteger('grade');
            $t->year('year')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->dateTime('deleted_at')->nullable();
        });
        foreach (['class_teachers' => 'id', 'examiners' => 'examiner_id'] as $table => $pk) {
            $s->create($table, function (Blueprint $t) use ($pk, $table, $connection) {
                $t->increments($pk);
                $t->string('name');
                $t->string('username');
                $t->string('password');
                if (! ($table === 'examiners' && $connection === self::JSS)) {
                    $t->string('class_assigned')->nullable();
                }
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->dateTime('deleted_at')->nullable();
            });
        }
        $s->create('exams', function (Blueprint $t) {
            $t->increments('exam_id');
            $t->string('exam_name');
            $t->string('exam_type', 20);       // enum in production; '' occurs
            $t->string('term', 10);
            $t->string('status', 20)->nullable();
            $t->string('academic_year', 4)->nullable();   // YEAR in production; '0000' and NULL occur
            $t->timestamp('date_created')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->dateTime('deleted_at')->nullable();
        });
        $s->create('students', function (Blueprint $t) use ($withPno) {
            $t->increments('student_id');
            $t->string('name');
            if ($withPno) {
                $t->string('pno', 20)->nullable();
            }
            $t->string('class', 50);
            $t->string('status', 20)->nullable();
            $t->dateTime('finished_at')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->dateTime('deleted_at')->nullable();
        });
        $s->create('student_classes', function (Blueprint $t) {
            $t->increments('student_class_id');
            $t->integer('student_id');
            $t->string('class', 50);
            $t->string('academic_year', 4);
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->dateTime('deleted_at')->nullable();
        });
        $s->create('exam_results', function (Blueprint $t) use ($examResultColumns, $totalColumn) {
            $t->increments('result_id');
            $t->integer('exam_id')->nullable();
            $t->integer('student_id')->nullable();
            foreach ($examResultColumns as $column) {
                $t->integer($column)->nullable();
            }
            $t->integer($totalColumn)->nullable();
            $t->integer('position')->nullable();
            $t->integer('stream_position')->nullable();
            $t->integer('student_class_id');
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->dateTime('deleted_at')->nullable();
        });
    }

    private static function lowerSchema(): void
    {
        self::commonTables(self::LOWER, true, ['Math', 'English', 'Kiswahili', 'Reading', 'Kusoma', 'Enviromental',
            'Creative', 'Religious', 'Integrated', 'LS/SP', 'RDG', 'GRM', 'WRI', 'KUS/KUZ', 'KUS', 'LUG', 'KUA'], 'Total_marks');
        $s = Schema::connection(self::LOWER);
        $s->create('marks_out_of', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('exam_id');
            $t->string('subject', 50);
            $t->integer('marks_out_of');
        });
        $s->create('point_boundaries', function (Blueprint $t) {
            $t->increments('id');
            $t->string('subject', 50);
            $t->string('grade', 2);
            $t->integer('min_marks');
            $t->integer('max_marks');
            $t->string('pl', 250);
            $t->string('ab', 10);
        });
        $s->create('users', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name', 250);
            $t->string('username', 50);
            $t->string('password');
            $t->string('class_assigned', 50);
            $t->dateTime('created_at')->nullable();
        });
    }

    private static function upperSchema(): void
    {
        // Upper production has no pno column at all.
        self::commonTables(self::UPPER, false, ['Math', 'English', 'Kiswahili', 'SciTech', 'AgricNutri', 'Creative', 'CRE', 'SST'], 'Total_marks');
        $s = Schema::connection(self::UPPER);
        $s->create('marks_out_of', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('exam_id');
            $t->string('subject', 50);
            $t->integer('marks_out_of');
        });
        $s->create('point_boundaries', function (Blueprint $t) {
            $t->increments('id');
            $t->string('grade', 2);
            $t->integer('min_marks');
            $t->integer('max_marks');
            $t->string('pl', 250);
            $t->string('ab', 10);
        });
    }

    private static function jssSchema(): void
    {
        self::commonTables(self::JSS, true, ['Math', 'English', 'Kiswahili', 'Technical', 'Agriculture', 'Creative', 'Religious',
            'SST', 'Science', 'Paper1English', 'Paper2English', 'paper1Kiswahili', 'paper2Kiswahili'], 'total_marks');
        $s = Schema::connection(self::JSS);
        $s->create('point_boundaries', function (Blueprint $t) {
            $t->increments('id');
            $t->string('grade', 2);
            $t->integer('min_marks');
            $t->integer('max_marks');
            $t->string('pl', 250);
            $t->string('ab', 10);
        });
        $s->create('subjects', function (Blueprint $t) {
            $t->increments('subject_id');
            $t->string('name');
        });
        $s->create('examiner_subject_classes', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('examiner_id');
            $t->integer('subject_id');
            $t->integer('class_id');
            $t->dateTime('assigned_date')->nullable();
        });
    }

    // --------------------------------------------------------------------- data

    private static function staff(string $name, string $username, ?string $class = null, bool $withClass = true): array
    {
        $row = ['name' => $name, 'username' => $username, 'password' => self::hash(), 'created_at' => '2025-01-10 08:00:00'];

        return $withClass ? $row + ['class_assigned' => $class] : $row;
    }

    private static function lowerData(): void
    {
        $c = self::LOWER;
        self::insert($c, 'admins', [['name' => 'Head Admin', 'username' => 'admin', 'password' => self::hash()]]);
        self::insert($c, 'class_teachers', [
            self::staff('John Kamau', 'jkamau', 'grade_2west'),
            self::staff('Nobody Assigned', 'noclass', ''),
        ]);
        self::insert($c, 'examiners', [
            self::staff('John Kamau', 'jkamau', 'grade_2west'),     // same person as the class teacher
            self::staff('Peter Wanjiru', 'pwanjiru', 'grade_1east'),
        ]);
        self::insert($c, 'users', [['name' => 'Dead Table', 'username' => 'olduser', 'password' => self::hash(), 'class_assigned' => 'grade_1east']]);
        self::insert($c, 'classes', [
            ['class_name' => 'grade_1east', 'grade' => 1], ['class_name' => 'grade_2west', 'grade' => 2],
            ['class_name' => 'grade_3', 'grade' => 3], ['class_name' => 'GRADE 3 EAST', 'grade' => 3],
        ]);

        // 1 leaves after Grade 3 in 2025 and joins upper Grade 4 in 2026.
        // 2 moves up normally. 3 has two class rows in 2026. 4 shares a name with two
        // upper pupils (ambiguous). 5 is test data. 6 shares a unique name with an
        // upper pupil but the grades do not follow on (asked, never auto-merged).
        self::insert($c, 'students', [
            ['name' => 'Amani Juma Otieno', 'pno' => null, 'class' => 'Completed', 'status' => 'Finished', 'finished_at' => '2025-11-20 00:00:00'],
            ['name' => 'Baraka  Mwangi', 'pno' => '', 'class' => 'grade_2west', 'status' => 'Active'],
            ['name' => 'Chebet Rono', 'pno' => null, 'class' => 'GRADE 3 EAST', 'status' => 'Active'],
            ['name' => 'Duplicate Name', 'pno' => null, 'class' => 'grade_2west', 'status' => 'Active'],
            ['name' => 'Test Pupil', 'pno' => null, 'class' => '6_test', 'status' => 'Active'],
            ['name' => 'Grace Wairimu', 'pno' => null, 'class' => 'grade_1east', 'status' => 'Active'],
        ]);
        self::insert($c, 'student_classes', [
            ['student_id' => 1, 'class' => 'grade_3', 'academic_year' => '2025', 'created_at' => '2025-01-10 00:00:00'],      // 1
            ['student_id' => 1, 'class' => 'Completed', 'academic_year' => '2026', 'created_at' => '2026-01-05 00:00:00'],    // 2
            ['student_id' => 2, 'class' => 'grade_1east', 'academic_year' => '2025', 'created_at' => '2025-01-10 00:00:00'],  // 3
            ['student_id' => 2, 'class' => 'grade_2west', 'academic_year' => '2026', 'created_at' => '2026-01-05 00:00:00'],  // 4
            ['student_id' => 3, 'class' => 'grade_3', 'academic_year' => '2025', 'created_at' => '2025-01-10 00:00:00'],      // 5
            ['student_id' => 3, 'class' => 'GRADE 3 EAST', 'academic_year' => '2026', 'created_at' => '2026-01-06 00:00:00'], // 6 (duplicate year, correct: results link here)
            ['student_id' => 3, 'class' => 'grade_2west', 'academic_year' => '2026', 'created_at' => '2026-01-07 00:00:00'],  // 7 (duplicate year, mistaken, and newer)
            ['student_id' => 4, 'class' => 'grade_2west', 'academic_year' => '2026', 'created_at' => '2026-01-05 00:00:00'],  // 8
            ['student_id' => 5, 'class' => '6_test', 'academic_year' => '2026', 'created_at' => '2026-01-05 00:00:00'],       // 9
            ['student_id' => 6, 'class' => 'grade_1east', 'academic_year' => '2025', 'created_at' => '2025-01-10 00:00:00'],  // 10
            // The year rollover copied student 1's class into 2026 just before they were finished.
            ['student_id' => 1, 'class' => 'grade_3', 'academic_year' => '2026', 'created_at' => '2026-01-05 00:00:00'],      // 11
        ]);
        self::insert($c, 'exams', [
            ['exam_name' => 'Term 1 Mid-Term', 'exam_type' => 'Mid-Term', 'term' => 'Term 1', 'status' => 'Completed', 'academic_year' => '2025', 'date_created' => '2025-01-18 09:00:00'], // 1
            ['exam_name' => 'Term 1 Mid-Term', 'exam_type' => 'Mid-Term', 'term' => 'Term 1', 'status' => 'Completed', 'academic_year' => '0000', 'date_created' => '2026-02-11 09:00:00'], // 2
            ['exam_name' => 'Term 1 End-Term', 'exam_type' => 'End-Term', 'term' => 'Term 1', 'status' => 'Completed', 'academic_year' => '2025', 'date_created' => '2026-03-23 09:00:00'], // 3 (stored year wrong)
            ['exam_name' => 'Term 3 Opener', 'exam_type' => 'Opener', 'term' => 'Term 3', 'status' => 'Scheduled', 'academic_year' => '0000', 'date_created' => '2026-08-25 09:00:00'],    // 4
            ['exam_name' => 'Untyped', 'exam_type' => '', 'term' => 'Term 1', 'status' => 'Completed', 'academic_year' => '2025', 'date_created' => '2025-01-23 09:00:00'],                // 5
        ]);
        self::insert($c, 'marks_out_of', [
            ['exam_id' => 3, 'subject' => 'RDG', 'marks_out_of' => 50], ['exam_id' => 3, 'subject' => 'GRM', 'marks_out_of' => 50],
            ['exam_id' => 3, 'subject' => 'KUS', 'marks_out_of' => 40], ['exam_id' => 3, 'subject' => 'LUG', 'marks_out_of' => 60],
            ['exam_id' => 1, 'subject' => 'Environmental', 'marks_out_of' => 100],
        ]);
        foreach ([['', 0, 23, 'BELOW EXPECTATIONS', 'BE-1'], ['', 24, 49, 'APPROACHING EXPECTATIONS', 'AE-2'],
            ['', 50, 74, 'MEETING EXPECTATIONS', 'ME-3'], ['', 75, 100, 'EXCEEDING EXPECTATIONS', 'EE-4'],
            ['English', 0, 0, 'EXCEEDING EXPECTATIONS', 'EE'], ['English', 0, 0, 'MEETING EXPECTATIONS', 'ME']] as $i => [$subject, $min, $max, $pl, $ab]) {
            self::insert($c, 'point_boundaries', [['subject' => $subject, 'grade' => (string) ($i + 1), 'min_marks' => $min, 'max_marks' => $max, 'pl' => $pl, 'ab' => $ab]]);
        }

        $row = fn (int $exam, int $student, int $link, array $marks, ?int $total = null, ?int $position = null) => array_merge(
            array_fill_keys(['Math', 'English', 'Kiswahili', 'Reading', 'Kusoma', 'Enviromental', 'Creative', 'Religious',
                'Integrated', 'LS/SP', 'RDG', 'GRM', 'WRI', 'KUS/KUZ', 'KUS', 'LUG', 'KUA'], null),
            ['exam_id' => $exam, 'student_id' => $student, 'student_class_id' => $link, 'Total_marks' => $total, 'position' => $position, 'stream_position' => null, 'created_at' => '2025-01-20 00:00:00'],
            $marks,
        );

        self::insert($c, 'exam_results', [
            // 2025: every subject single-paper, Reading and Kusoma counted.
            $row(1, 1, 1, ['Math' => 80, 'English' => 70, 'Kiswahili' => 60, 'Reading' => 75, 'Kusoma' => 65, 'Enviromental' => 90, 'Creative' => 85, 'Religious' => 55], 580, 1),
            $row(1, 3, 5, ['Math' => 40, 'English' => 50, 'Kiswahili' => 45, 'Reading' => 35, 'Kusoma' => 30, 'Enviromental' => 60, 'Creative' => 70, 'Religious' => 65], 395, 2),
            $row(1, 2, 3, ['Math' => 66, 'English' => 64, 'Kiswahili' => 62, 'Integrated' => 1]),
            // Student 4 has no 2025 class row at all: the placement is inferred and flagged.
            $row(1, 4, 8, ['Math' => 33, 'English' => 41]),
            // 2026 first exam: class Grade 2 West still used a single English mark,
            // Grade 3 East already used RDG + GRM. Student 2's result links to the
            // 2025 row (the production cross-year bug).
            $row(2, 2, 3, ['Math' => 71, 'English' => 68, 'Kiswahili' => 58, 'Enviromental' => 77, 'Creative' => 80, 'Religious' => 73]),
            $row(2, 3, 6, ['Math' => 52, 'RDG' => 30, 'GRM' => 36, 'KUS' => 28, 'LUG' => 40, 'Enviromental' => 61, 'Creative' => 64, 'Religious' => 59, 'LS/SP' => 0]),
            // 2026 End-Term: strands everywhere, marks_out_of RDG/GRM 50, KUS 40, LUG 60.
            // Student 3 also has a stored English (legacy double-count era) and a WRI mark.
            $row(3, 3, 5, ['Math' => 58, 'RDG' => 41, 'GRM' => 44, 'English' => 85, 'KUS' => 30, 'LUG' => 45, 'Enviromental' => 66, 'Creative' => 70, 'Religious' => 62, 'WRI' => 55], 461),
            $row(3, 2, 4, ['Math' => 74, 'RDG' => 38, 'GRM' => 40, 'KUS' => 33, 'LUG' => 50, 'Enviromental' => 79, 'Creative' => 82, 'Religious' => 70]),
            // Grade 2 West has papers this time, but student 4 has only a combined English: unmapped.
            $row(3, 4, 8, ['Math' => 50, 'English' => 60, 'KUS' => 20, 'LUG' => 30]),
            // The test pupil in "6_test" sat the Mid-Term (test data).
            $row(2, 5, 9, ['Math' => 12, 'Creative' => 15]),
            // Scheduled current exam, marking in progress.
            $row(4, 2, 4, ['Math' => 69]),
        ]);
    }

    private static function upperData(): void
    {
        $c = self::UPPER;
        self::insert($c, 'admins', [['name' => 'Head Admin', 'username' => 'admin', 'password' => self::hash()]]);
        self::insert($c, 'class_teachers', [self::staff('Mary Njeri', 'mary', 'grade_4east')]);
        self::insert($c, 'examiners', [
            self::staff('Mary Njeri', 'mary', 'grade_4east'),
            self::staff('Tomas Oduor', 'tom', 'grade_5west'),        // same username as a different JSS person
        ]);
        self::insert($c, 'classes', [['class_name' => 'grade_4east', 'grade' => 4], ['class_name' => 'grade_5west', 'grade' => 5], ['class_name' => 'grade_6east', 'grade' => 6]]);
        self::insert($c, 'students', [
            ['name' => 'Amani Juma Otieno', 'class' => 'grade_4east', 'status' => 'Active'],   // 1: the lower pupil, moved up
            ['name' => 'Duplicate Name', 'class' => 'grade_4east', 'status' => 'Active'],      // 2: ambiguous
            ['name' => 'Duplicate Name', 'class' => 'grade_5west', 'status' => 'Active'],      // 3: ambiguous
            ['name' => 'Faith Akinyi', 'class' => 'Completed', 'status' => 'Finished'],        // 4: leaves Grade 6 in 2025, JSS in 2026
            ['name' => 'Grace Wairimu', 'class' => 'grade_5west', 'status' => 'Active'],       // 5: same name as lower 6, not a move up
            ['name' => 'Kelvin Otieno', 'class' => 'Completed', 'status' => 'Finished'],       // 6, 7: two leavers with one name; both
            ['name' => 'Kelvin Otieno', 'class' => 'Completed', 'status' => 'Finished'],       //   "move up" to JSS 3, so neither is sure
        ]);
        self::insert($c, 'student_classes', [
            ['student_id' => 1, 'class' => 'grade_4east', 'academic_year' => '2026'],   // 1
            ['student_id' => 2, 'class' => 'grade_4east', 'academic_year' => '2026'],   // 2
            ['student_id' => 3, 'class' => 'grade_5west', 'academic_year' => '2026'],   // 3
            ['student_id' => 4, 'class' => 'grade_6east', 'academic_year' => '2025'],   // 4
            ['student_id' => 5, 'class' => 'grade_5west', 'academic_year' => '2026'],   // 5
            ['student_id' => 6, 'class' => 'grade_6east', 'academic_year' => '2025'],   // 6
            ['student_id' => 7, 'class' => 'grade_6east', 'academic_year' => '2025'],   // 7
        ]);
        self::insert($c, 'exams', [
            ['exam_name' => 'Term 3 End-Term', 'exam_type' => 'End-Term', 'term' => 'Term 3', 'status' => 'Completed', 'academic_year' => null, 'date_created' => '2025-10-14 09:00:00'], // 1
            ['exam_name' => 'Term 1 Mid-Term', 'exam_type' => 'Mid-Term', 'term' => 'Term 1', 'status' => 'Completed', 'academic_year' => null, 'date_created' => '2026-02-11 09:00:00'], // 2
        ]);
        self::insert($c, 'marks_out_of', [['exam_id' => 2, 'subject' => 'Science & Technology', 'marks_out_of' => 50]]);
        foreach ([[0, 23, 'BELOW EXPECTATIONS', 'BE-1'], [24, 49, 'APPROACHING EXPECTATIONS', 'AE-2'], [50, 79, 'MEETING EXPECTATIONS', 'ME-3'], [80, 100, 'EXCEEDING EXPECTATIONS', 'EE-4']] as $i => [$min, $max, $pl, $ab]) {
            self::insert($c, 'point_boundaries', [['grade' => (string) ($i + 1), 'min_marks' => $min, 'max_marks' => $max, 'pl' => $pl, 'ab' => $ab]]);
        }
        $row = fn (int $exam, int $student, int $link, array $marks, ?int $total) => array_merge(
            array_fill_keys(['Math', 'English', 'Kiswahili', 'SciTech', 'AgricNutri', 'Creative', 'CRE', 'SST'], null),
            ['exam_id' => $exam, 'student_id' => $student, 'student_class_id' => $link, 'Total_marks' => $total, 'position' => 1, 'stream_position' => 1],
            $marks,
        );
        $all = fn (int $v) => ['Math' => $v, 'English' => $v + 2, 'Kiswahili' => $v + 4, 'SciTech' => $v - 3, 'AgricNutri' => $v + 1, 'Creative' => $v + 5, 'CRE' => $v - 1, 'SST' => $v + 3];
        self::insert($c, 'exam_results', [
            $row(1, 4, 4, $all(70), 571),
            $row(2, 1, 1, $all(60), 491),
            $row(2, 2, 2, $all(50), 999),   // stale legacy total
            $row(2, 3, 3, $all(40), 331),
        ]);
    }

    private static function jssData(): void
    {
        $c = self::JSS;
        self::insert($c, 'admins', [['name' => 'Head Admin', 'username' => 'admin', 'password' => self::hash()]]);
        self::insert($c, 'class_teachers', [self::staff('Grace Muthoni', 'gmuthoni', '7')]);
        self::insert($c, 'examiners', [
            self::staff('Grace Muthoni', 'gmuthoni', null, false),
            self::staff('Tom Otieno', 'tom', null, false),           // same username as upper's Tomas Oduor
        ]);
        self::insert($c, 'classes', [['class_name' => '7', 'grade' => 7], ['class_name' => '8', 'grade' => 8]]);
        self::insert($c, 'subjects', array_map(fn ($n) => ['name' => $n], ['Math', 'English', 'Kiswahili', 'Creative', 'Technical', 'Agriculture', 'Religious', 'SST', 'Science']));
        self::insert($c, 'examiner_subject_classes', [
            ['examiner_id' => 1, 'subject_id' => 2, 'class_id' => 1],   // Grace: English, Grade 7
            ['examiner_id' => 2, 'subject_id' => 1, 'class_id' => 1],   // Tom: Maths, Grade 7
        ]);
        self::insert($c, 'students', [
            ['name' => 'Faith Akinyi', 'pno' => null, 'class' => '7', 'status' => 'Active'],   // 1: from upper Grade 6
            ['name' => 'Kevin Mutua', 'pno' => null, 'class' => '7', 'status' => 'Active'],   // 2
            ['name' => 'Kelvin Otieno', 'pno' => null, 'class' => '7', 'status' => 'Active'], // 3: one of upper 6 and 7
        ]);
        self::insert($c, 'student_classes', [
            ['student_id' => 1, 'class' => '7', 'academic_year' => '2026'],   // 1
            ['student_id' => 2, 'class' => '7', 'academic_year' => '2026'],   // 2
            ['student_id' => 3, 'class' => '7', 'academic_year' => '2026'],   // 3
        ]);
        self::insert($c, 'exams', [
            ['exam_name' => 'Term 1 Mid-Term', 'exam_type' => 'Mid-Term', 'term' => 'Term 1', 'status' => 'Completed', 'academic_year' => null, 'date_created' => '2026-02-11 09:00:00'], // 1
            ['exam_name' => 'Term 1 End-Term', 'exam_type' => 'End-Term', 'term' => 'Term 1', 'status' => 'Completed', 'academic_year' => null, 'date_created' => '2026-03-27 09:00:00'], // 2
        ]);
        foreach ([[1, 10, 'BE-1'], [11, 20, 'BE-2'], [21, 30, 'AE-1'], [31, 40, 'AE-2'], [41, 57, 'ME-1'], [58, 74, 'ME-2'], [75, 89, 'EE-1'], [90, 100, 'EE-2']] as $i => [$min, $max, $ab]) {
            $pl = ['BE' => 'BELOW EXPECTATIONS', 'AE' => 'APPROACHING EXPECTATIONS', 'ME' => 'MEETING EXPECTATIONS', 'EE' => 'EXCEEDING EXPECTATIONS'][substr($ab, 0, 2)];
            self::insert($c, 'point_boundaries', [['grade' => (string) ($i + 1), 'min_marks' => $min, 'max_marks' => $max, 'pl' => $pl, 'ab' => $ab]]);
        }
        $row = fn (int $exam, int $student, int $link, array $marks, ?int $total) => array_merge(
            array_fill_keys(['Math', 'English', 'Kiswahili', 'Technical', 'Agriculture', 'Creative', 'Religious', 'SST', 'Science',
                'Paper1English', 'Paper2English', 'paper1Kiswahili', 'paper2Kiswahili'], null),
            ['exam_id' => $exam, 'student_id' => $student, 'student_class_id' => $link, 'total_marks' => $total, 'position' => 1, 'stream_position' => 1],
            $marks,
        );
        $rest = ['Math' => 55, 'Technical' => 60, 'Agriculture' => 65, 'Creative' => 70, 'Religious' => 75, 'SST' => 50, 'Science' => 45];
        self::insert($c, 'exam_results', [
            // Mid-Term 2026: single English / Kiswahili marks.
            $row(1, 1, 1, $rest + ['English' => 62, 'Kiswahili' => 58], 540),
            $row(1, 2, 2, $rest + ['English' => 48, 'Kiswahili' => 52], 520),
            // End-Term 2026: Paper 1 + Paper 2 (out of 50), stored English = evidence.
            // Faith's stored English disagrees with her papers (as in 59 production rows).
            $row(2, 1, 1, $rest + ['Paper1English' => 30, 'Paper2English' => 35, 'English' => 70, 'paper1Kiswahili' => 25, 'paper2Kiswahili' => 27, 'Kiswahili' => 52], 542),
            // Kevin has only Paper 1 in English.
            $row(2, 2, 2, $rest + ['Paper1English' => 20, 'English' => 40, 'paper1Kiswahili' => 22, 'paper2Kiswahili' => 24, 'Kiswahili' => 46], 506),
        ]);
    }
}
