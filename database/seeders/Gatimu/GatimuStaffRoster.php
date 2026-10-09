<?php

declare(strict_types=1);

namespace Database\Seeders\Gatimu;

/**
 * The teaching staff of the Gatimu test school: the one list every Gatimu
 * seeder reads, so accounts (StaffSeeder), class teachers and examiners
 * (ClassesAndPupilsSeeder) and the subjects each teacher can teach
 * (TimetableInputsSeeder) agree.
 *
 * Synthetic people for local testing only — never a real person's name in
 * a fixture (CLAUDE.md rule 16 applies to children; we keep staff synthetic too).
 *
 * `subjects` is level code => subject codes (the school's `subjects.code`),
 * spread so every level subject has at least two qualified teachers and the
 * qualified teachers' periods comfortably cover all 18 classes:
 *
 *   lower primary   162 lessons a week   6 teachers + 2 shared
 *   upper primary   186                  7 teachers + 4 shared
 *   junior sec.     240                  9 teachers + 4 shared
 *
 * `class_teacher_of` names the class (as "Grade N Blue|Red") the person is
 * class teacher of, or null. Everyone holds Teacher and Examiner.
 */
final class GatimuStaffRoster
{
    public const PASSWORD = 'password';

    /** @var list<array{name: string, username: string, email: string, phone: string, tsc_no: string, staff_no: string, roles: list<string>, class_teacher_of: ?string, subjects: array<string, list<string>>}> */
    public const STAFF = [
        // ---------------------------------------------------- lower primary
        ['name' => 'Grace Wanjiku', 'username' => 'gwanjiku', 'email' => 'gwanjiku@gatimu.educa.test', 'phone' => '+254711000101', 'tsc_no' => '700101', 'staff_no' => 'GAT-S01', 'roles' => ['teacher', 'examiner', 'class_teacher'], 'class_teacher_of' => 'Grade 1 Blue', 'subjects' => ['lower_primary' => ['MAT', 'ENG', 'ENV']]],
        ['name' => 'Faith Atieno', 'username' => 'fatieno', 'email' => 'fatieno@gatimu.educa.test', 'phone' => '+254711000102', 'tsc_no' => '700102', 'staff_no' => 'GAT-S02', 'roles' => ['teacher', 'examiner', 'class_teacher'], 'class_teacher_of' => 'Grade 1 Red', 'subjects' => ['lower_primary' => ['MAT', 'KIS', 'CRE']]],
        ['name' => 'Mercy Njeri', 'username' => 'mnjeri', 'email' => 'mnjeri@gatimu.educa.test', 'phone' => '+254711000103', 'tsc_no' => '700103', 'staff_no' => 'GAT-S03', 'roles' => ['teacher', 'examiner', 'class_teacher'], 'class_teacher_of' => 'Grade 2 Blue', 'subjects' => ['lower_primary' => ['ENG', 'KIS', 'CRA']]],
        ['name' => 'Lucy Chebet', 'username' => 'lchebet', 'email' => 'lchebet@gatimu.educa.test', 'phone' => '+254711000104', 'tsc_no' => '700104', 'staff_no' => 'GAT-S04', 'roles' => ['teacher', 'examiner', 'class_teacher'], 'class_teacher_of' => 'Grade 2 Red', 'subjects' => ['lower_primary' => ['ENV', 'CRA', 'CRE']]],
        ['name' => 'Ann Mumbua', 'username' => 'amumbua', 'email' => 'amumbua@gatimu.educa.test', 'phone' => '+254711000105', 'tsc_no' => '700105', 'staff_no' => 'GAT-S05', 'roles' => ['teacher', 'examiner', 'class_teacher'], 'class_teacher_of' => 'Grade 3 Blue', 'subjects' => ['lower_primary' => ['MAT', 'ENG', 'CRA']]],
        ['name' => 'Joyce Achieng', 'username' => 'jachieng', 'email' => 'jachieng@gatimu.educa.test', 'phone' => '+254711000106', 'tsc_no' => '700106', 'staff_no' => 'GAT-S06', 'roles' => ['teacher', 'examiner', 'class_teacher'], 'class_teacher_of' => 'Grade 3 Red', 'subjects' => ['lower_primary' => ['KIS', 'ENV', 'CRA']]],

        // ---------------------------------------------------- upper primary
        ['name' => 'Peter Mwangi', 'username' => 'pmwangi', 'email' => 'pmwangi@gatimu.educa.test', 'phone' => '+254711000107', 'tsc_no' => '700107', 'staff_no' => 'GAT-S07', 'roles' => ['teacher', 'examiner', 'class_teacher'], 'class_teacher_of' => 'Grade 4 Blue', 'subjects' => ['upper_primary' => ['MAT', 'SCI']]],
        ['name' => 'Esther Wambui', 'username' => 'ewambui', 'email' => 'ewambui@gatimu.educa.test', 'phone' => '+254711000108', 'tsc_no' => '700108', 'staff_no' => 'GAT-S08', 'roles' => ['teacher', 'examiner', 'class_teacher'], 'class_teacher_of' => 'Grade 4 Red', 'subjects' => ['upper_primary' => ['ENG', 'CRE']]],
        ['name' => 'Hassan Omar', 'username' => 'homar', 'email' => 'homar@gatimu.educa.test', 'phone' => '+254711000109', 'tsc_no' => '700109', 'staff_no' => 'GAT-S09', 'roles' => ['teacher', 'examiner', 'class_teacher'], 'class_teacher_of' => 'Grade 5 Blue', 'subjects' => ['upper_primary' => ['KIS', 'SST']]],
        ['name' => 'Daniel Kiptoo', 'username' => 'dkiptoo', 'email' => 'dkiptoo@gatimu.educa.test', 'phone' => '+254711000110', 'tsc_no' => '700110', 'staff_no' => 'GAT-S10', 'roles' => ['teacher', 'examiner', 'class_teacher'], 'class_teacher_of' => 'Grade 5 Red', 'subjects' => ['upper_primary' => ['SCI', 'AGN']]],
        ['name' => 'Rose Nyambura', 'username' => 'rnyambura', 'email' => 'rnyambura@gatimu.educa.test', 'phone' => '+254711000111', 'tsc_no' => '700111', 'staff_no' => 'GAT-S11', 'roles' => ['teacher', 'examiner', 'class_teacher'], 'class_teacher_of' => 'Grade 6 Blue', 'subjects' => ['upper_primary' => ['MAT', 'AGN']]],
        ['name' => 'Samuel Otieno', 'username' => 'sotieno', 'email' => 'sotieno@gatimu.educa.test', 'phone' => '+254711000112', 'tsc_no' => '700112', 'staff_no' => 'GAT-S12', 'roles' => ['teacher', 'examiner', 'class_teacher'], 'class_teacher_of' => 'Grade 6 Red', 'subjects' => ['upper_primary' => ['ENG', 'SST', 'CRA']]],
        ['name' => 'Beatrice Kerubo', 'username' => 'bkerubo', 'email' => 'bkerubo@gatimu.educa.test', 'phone' => '+254711000113', 'tsc_no' => '700113', 'staff_no' => 'GAT-S13', 'roles' => ['teacher', 'examiner'], 'class_teacher_of' => null, 'subjects' => ['upper_primary' => ['KIS', 'CRA', 'CRE']]],

        // ------------------------------------------------- junior secondary
        ['name' => 'John Kamau', 'username' => 'jkamau', 'email' => 'jkamau@gatimu.educa.test', 'phone' => '+254711000114', 'tsc_no' => '700114', 'staff_no' => 'GAT-S14', 'roles' => ['teacher', 'examiner', 'class_teacher'], 'class_teacher_of' => 'Grade 7 Blue', 'subjects' => ['junior_secondary' => ['MAT', 'PTS']]],
        ['name' => 'Mary Wairimu', 'username' => 'mwairimu', 'email' => 'mwairimu@gatimu.educa.test', 'phone' => '+254711000115', 'tsc_no' => '700115', 'staff_no' => 'GAT-S15', 'roles' => ['teacher', 'examiner', 'class_teacher'], 'class_teacher_of' => 'Grade 7 Red', 'subjects' => ['junior_secondary' => ['ENG', 'CRE']]],
        ['name' => 'Ibrahim Abdi', 'username' => 'iabdi', 'email' => 'iabdi@gatimu.educa.test', 'phone' => '+254711000116', 'tsc_no' => '700116', 'staff_no' => 'GAT-S16', 'roles' => ['teacher', 'examiner', 'class_teacher'], 'class_teacher_of' => 'Grade 8 Blue', 'subjects' => ['junior_secondary' => ['KIS', 'SST']]],
        ['name' => 'Catherine Moraa', 'username' => 'cmoraa', 'email' => 'cmoraa@gatimu.educa.test', 'phone' => '+254711000117', 'tsc_no' => '700117', 'staff_no' => 'GAT-S17', 'roles' => ['teacher', 'examiner', 'class_teacher'], 'class_teacher_of' => 'Grade 8 Red', 'subjects' => ['junior_secondary' => ['ISC', 'AGR']]],
        ['name' => 'Kevin Ouma', 'username' => 'kouma', 'email' => 'kouma@gatimu.educa.test', 'phone' => '+254711000118', 'tsc_no' => '700118', 'staff_no' => 'GAT-S18', 'roles' => ['teacher', 'examiner', 'class_teacher'], 'class_teacher_of' => 'Grade 9 Blue', 'subjects' => ['junior_secondary' => ['MAT', 'ISC']]],
        ['name' => 'Purity Jepkosgei', 'username' => 'pjepkosgei', 'email' => 'pjepkosgei@gatimu.educa.test', 'phone' => '+254711000119', 'tsc_no' => '700119', 'staff_no' => 'GAT-S19', 'roles' => ['teacher', 'examiner', 'class_teacher'], 'class_teacher_of' => 'Grade 9 Red', 'subjects' => ['junior_secondary' => ['ENG', 'CRA']]],
        ['name' => 'Moses Mutua', 'username' => 'mmutua', 'email' => 'mmutua@gatimu.educa.test', 'phone' => '+254711000120', 'tsc_no' => '700120', 'staff_no' => 'GAT-S20', 'roles' => ['teacher', 'examiner'], 'class_teacher_of' => null, 'subjects' => ['junior_secondary' => ['PTS', 'AGR']]],
        ['name' => 'Naomi Wanjala', 'username' => 'nwanjala', 'email' => 'nwanjala@gatimu.educa.test', 'phone' => '+254711000121', 'tsc_no' => '700121', 'staff_no' => 'GAT-S21', 'roles' => ['teacher', 'examiner'], 'class_teacher_of' => null, 'subjects' => ['junior_secondary' => ['KIS', 'CRA', 'CRE']]],
        ['name' => 'Victor Kiprono', 'username' => 'vkiprono', 'email' => 'vkiprono@gatimu.educa.test', 'phone' => '+254711000122', 'tsc_no' => '700122', 'staff_no' => 'GAT-S22', 'roles' => ['teacher', 'examiner'], 'class_teacher_of' => null, 'subjects' => ['junior_secondary' => ['SST', 'ISC']]],

        // ------------------------------------------------- across levels
        ['name' => 'Elizabeth Muthoni', 'username' => 'emuthoni', 'email' => 'emuthoni@gatimu.educa.test', 'phone' => '+254711000123', 'tsc_no' => '700123', 'staff_no' => 'GAT-S23', 'roles' => ['teacher', 'examiner'], 'class_teacher_of' => null, 'subjects' => ['lower_primary' => ['MAT'], 'upper_primary' => ['MAT'], 'junior_secondary' => ['MAT']]],
        ['name' => 'George Njoroge', 'username' => 'gnjoroge', 'email' => 'gnjoroge@gatimu.educa.test', 'phone' => '+254711000124', 'tsc_no' => '700124', 'staff_no' => 'GAT-S24', 'roles' => ['teacher', 'examiner'], 'class_teacher_of' => null, 'subjects' => ['upper_primary' => ['ENG'], 'junior_secondary' => ['ENG']]],
        ['name' => 'Alice Akoth', 'username' => 'aakoth', 'email' => 'aakoth@gatimu.educa.test', 'phone' => '+254711000125', 'tsc_no' => '700125', 'staff_no' => 'GAT-S25', 'roles' => ['teacher', 'examiner'], 'class_teacher_of' => null, 'subjects' => ['lower_primary' => ['CRE'], 'upper_primary' => ['CRE'], 'junior_secondary' => ['CRE']]],
        ['name' => 'Brian Kibet', 'username' => 'bkibet', 'email' => 'bkibet@gatimu.educa.test', 'phone' => '+254711000126', 'tsc_no' => '700126', 'staff_no' => 'GAT-S26', 'roles' => ['teacher', 'examiner'], 'class_teacher_of' => null, 'subjects' => ['upper_primary' => ['SCI'], 'junior_secondary' => ['ISC', 'AGR']]],
    ];
}
