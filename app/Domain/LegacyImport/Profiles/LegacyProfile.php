<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport\Profiles;

/**
 * What one legacy section's exam_results columns meant, year by year.
 *
 * Profiles are the only place legacy column names are interpreted. Every fact in
 * them was measured from the school's production data, not taken from the legacy
 * code's intentions — see docs/legacy-import.md, "What the data is".
 */
interface LegacyProfile
{
    public function system(): string;

    /** Columns of exam_results that are not marks. */
    public function metaColumns(): array;

    /** The legacy total column name (it differs in case between sections). */
    public function totalColumn(): string;

    /** @return list<SubjectSpec> */
    public function subjects(): array;

    /**
     * Candidate variants per subject for a year, most specific first. For each
     * exam and class the importer uses the first variant whose paper columns hold
     * data there.
     *
     * @return array<string, list<SubjectVariant>> keyed by subject code
     */
    public function variants(int $year): array;

    /** marks_out_of.subject spellings → exam_results column. */
    public function marksOutOfAliases(): array;

    /** Legacy subjects.name → catalogue code, for examiner_subject_classes (JSS only). */
    public function legacySubjectNames(): array;
}
