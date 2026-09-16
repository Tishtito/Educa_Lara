<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport\Steps;

use App\Domain\LegacyImport\ImportContext;
use App\Domain\LegacyImport\Quarantine;
use App\Domain\People\Models\ClassTeacherAssignment;
use App\Domain\People\Models\ExaminerAssignment;

/**
 * Who teaches and marks what, for the latest academic year (legacy recorded no
 * history of assignments).
 *
 *   class teachers      class_assigned → the class
 *   JSS examiners       examiner_subject_classes → exactly those subject × class
 *   lower/upper         examiners.class_assigned only → every subject of that
 *   examiners           class's level, marked origin=expanded_from_class_assigned
 *                       so the school can prune what legacy never actually said
 */
final class AssignmentStep implements ImportStep
{
    public function name(): string
    {
        return 'assignments';
    }

    public function run(ImportContext $context): void
    {
        if ($context->years === []) {
            return;
        }

        $year = max(array_keys($context->years));
        $academicYear = $context->year($year);

        foreach ($context->sources as $source) {
            $levelSubjects = collect($context->levelSubjects)
                ->filter(fn ($ls) => (int) $ls->level_id === (int) $source->level->id && (int) $ls->effective_from_year_id === (int) $academicYear->id);

            foreach ($source->table('class_teachers', 'id') as $row) {
                $userId = $context->map->targetId($source->system, 'class_teachers', $row['id'], 'users');
                $class = $userId === null ? null : $this->assignedClass($context, $source, $row, 'class_teachers');

                if ($class !== null) {
                    ClassTeacherAssignment::query()->updateOrCreate(
                        ['user_id' => $userId, 'class_id' => $class->id, 'academic_year_id' => $academicYear->id],
                        ['school_id' => $context->school->id, 'is_primary' => true],
                    );
                    $context->report->count($this->name(), 'class teacher assignments');
                }
            }

            if ($source->has('examiner_subject_classes')) {
                $subjectNames = $source->table('subjects', 'subject_id')->pluck('name', 'subject_id');
                $classNames = $source->table('classes', 'class_id')->pluck('class_name', 'class_id');
                $codes = $source->profile->legacySubjectNames();

                foreach ($source->table('examiner_subject_classes', 'id') as $row) {
                    $userId = $context->map->targetId($source->system, 'examiners', $row['examiner_id'], 'users');
                    $code = $codes[$subjectNames[$row['subject_id']] ?? ''] ?? null;
                    $class = $context->classFor($source, $classNames[$row['class_id']] ?? null, 'examiner_subject_classes');

                    if ($userId === null || $code === null || $class === null) {
                        continue;
                    }

                    foreach ($levelSubjects->filter(fn ($ls) => $ls->subject->code === $code) as $levelSubject) {
                        $this->examiner($context, $userId, $class->id, $levelSubject->id, $academicYear->id, null);
                    }
                }

                continue;
            }

            foreach ($source->table('examiners', 'examiner_id') as $row) {
                $userId = $context->map->targetId($source->system, 'examiners', $row['examiner_id'], 'users');
                $class = $userId === null ? null : $this->assignedClass($context, $source, $row, 'examiners');

                if ($class === null) {
                    continue;
                }

                foreach ($levelSubjects as $levelSubject) {
                    $this->examiner($context, $userId, $class->id, $levelSubject->id, $academicYear->id, ExaminerAssignment::ORIGIN_EXPANDED);
                }
            }
        }
    }

    private function assignedClass(ImportContext $context, $source, array $row, string $table)
    {
        $raw = trim((string) ($row['class_assigned'] ?? ''));

        if ($raw === '') {
            return null;
        }

        $class = $context->classFor($source, $raw, $table);

        if ($class === null && ($parsed = $context->parseClass($raw)) !== null && ! $parsed->isGraduationMarker) {
            $context->quarantine->add($source->system, $table, $row['id'] ?? $row['examiner_id'] ?? null, Quarantine::UNKNOWN_ASSIGNED_CLASS, ['class_assigned' => $raw]);
        }

        return $class;
    }

    private function examiner(ImportContext $context, int $userId, int $classId, int $levelSubjectId, int $yearId, ?string $origin): void
    {
        ExaminerAssignment::query()->updateOrCreate(
            ['user_id' => $userId, 'class_id' => $classId, 'level_subject_id' => $levelSubjectId, 'academic_year_id' => $yearId],
            ['school_id' => $context->school->id, 'origin' => $origin],
        );
        $context->report->count('assignments', $origin === null ? 'examiner assignments' : 'examiner assignments expanded from class_assigned');
    }
}
