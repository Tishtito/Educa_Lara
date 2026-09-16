<?php

declare(strict_types=1);

namespace App\Domain\LegacyImport\Steps;

use App\Domain\Grading\Models\GradeBand;
use App\Domain\Grading\Models\GradingScale;
use App\Domain\LegacyImport\ImportContext;
use App\Domain\LegacyImport\SourceContext;

/**
 * The school's own grading boundaries, per section, as its default scale for
 * that level (decided with the school: its scales, not the platform default).
 *
 * point_boundaries rows with no subject are the section's scale. Lower-classes
 * also has per-subject rows, some of them nonsense (English 0-0); those are kept
 * as flagged legacy_broken scales so they can be inspected, and GradeResolver
 * never uses a legacy_broken scale.
 *
 * JSS's scale starts at 1, which would leave a mark of 0 with no level; the
 * lowest band is widened to 0 and the change recorded.
 */
final class GradingStep implements ImportStep
{
    public function name(): string
    {
        return 'grading';
    }

    public function run(ImportContext $context): void
    {
        foreach ($context->sources as $source) {
            if (! $source->has('point_boundaries')) {
                continue;
            }

            $rows = $source->table('point_boundaries', 'id');
            $bySubject = $rows->groupBy(fn (array $r) => trim((string) ($r['subject'] ?? '')));

            foreach ($bySubject as $subject => $bands) {
                $this->importScale($context, $source, $subject, $bands->values()->all());
            }
        }
    }

    private function importScale(ImportContext $context, SourceContext $source, string $subject, array $rows): void
    {
        $general = $subject === '';
        $name = $general
            ? "{$source->level->name} (legacy)"
            : "{$source->level->name} {$subject} (legacy per-subject, unused)";

        if ($context->map->isUnchanged($source->system, 'point_boundaries', $subject ?: '*', 'grading_scales', $rows)) {
            return;
        }

        $original = $rows;   // the checksum is of the legacy rows as read, not as adjusted

        usort($rows, fn (array $a, array $b) => [(int) $a['min_marks'], (int) $a['max_marks']] <=> [(int) $b['min_marks'], (int) $b['max_marks']]);

        if ($general && (int) $rows[0]['min_marks'] > 0) {
            $context->report->note($this->name(), "{$source->level->name}: lowest band started at {$rows[0]['min_marks']}; widened to 0 so a mark of 0 has a level.");
            $rows[0]['min_marks'] = 0;
        }

        $scale = GradingScale::query()->updateOrCreate(
            ['school_id' => $context->school->id, 'level_id' => $source->level->id, 'name' => $name],
            ['kind' => 'performance_level', 'is_default' => $general, 'legacy_broken' => ! $general],
        );

        $scale->bands()->delete();

        foreach ($rows as $i => $row) {
            GradeBand::create([
                'grading_scale_id' => $scale->id,
                'label' => trim((string) $row['pl']),
                'abbreviation' => trim((string) $row['ab']),
                'points' => $i + 1,
                'min_marks' => (int) $row['min_marks'],
                'max_marks' => (int) $row['max_marks'],
                'sort_order' => $i + 1,
            ]);
        }

        if ($general && ($problems = $scale->validateBands()) !== []) {
            // Never let a broken scale grade anything; resolution falls back.
            $scale->forceFill(['legacy_broken' => true, 'is_default' => false])->save();
            $context->report->note($this->name(), "{$name} is not contiguous and will not be used: ".implode(' ', $problems));
        }

        // Other defaults for this level would compete with the imported one.
        if ($general && ! $scale->legacy_broken) {
            GradingScale::query()
                ->where('school_id', $context->school->id)
                ->where('level_id', $source->level->id)
                ->whereKeyNot($scale->id)
                ->update(['is_default' => false]);
        }

        $context->map->record($source->system, 'point_boundaries', $subject ?: '*', 'grading_scales', $scale->id, $original);
        $context->report->count($this->name(), $general ? 'section scales' : 'broken per-subject scales kept');
    }
}
