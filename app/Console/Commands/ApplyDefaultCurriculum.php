<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Curriculum\DefaultCurriculum;
use App\Domain\Tenancy\Models\School;
use Illuminate\Console\Command;

/** Adds the default CBC subjects to an existing school. Never changes what it already has. */
class ApplyDefaultCurriculum extends Command
{
    protected $signature = 'curriculum:defaults {school : School code (slug)}';

    protected $description = 'Add the default subjects for each level to a school, skipping any it already offers';

    public function handle(DefaultCurriculum $defaults): int
    {
        $school = School::query()->where('slug', $this->argument('school'))->first();

        if ($school === null) {
            $this->error('No school has the code "'.$this->argument('school').'".');

            return self::FAILURE;
        }

        $added = $defaults->apply($school);

        $this->info("{$school->name}: {$added['level_subjects_added']} subjects added across the levels ({$added['subjects_added']} new in the catalogue).");

        return self::SUCCESS;
    }
}
