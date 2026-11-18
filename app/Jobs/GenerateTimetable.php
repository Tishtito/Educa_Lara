<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\TenantManager;
use App\Domain\Timetabling\Enums\TimetableStatus;
use App\Domain\Timetabling\Models\Timetable;
use App\Domain\Timetabling\Services\TimetableGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generates one timetable. On the long connection (educa-general worker,
 * `timetables` queue): a big school's search can run for a minute.
 *
 * Holds the id, not the model — a worker has no tenant, so SerializesModels
 * would re-fetch through TenantScope and fail closed (see ComputeExamResults).
 * One try: the solver is deterministic for a seed, so retrying a failure would
 * only fail the same way.
 */
class GenerateTimetable implements ShouldQueue
{
    use Queueable;

    /** Below database-long's retry_after (660 s), and well above the solver's time limit. */
    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public readonly int $timetableId)
    {
        $this->onConnection(config('elimupass.queue.long_connection'));
        $this->onQueue('timetables');
        $this->afterCommit();
    }

    public function handle(TimetableGenerator $generator, TenantManager $tenancy): void
    {
        $timetable = $tenancy->withoutScope(fn () => Timetable::query()->find($this->timetableId));

        if ($timetable === null || $timetable->status !== TimetableStatus::QUEUED) {
            return;
        }

        $school = School::query()->findOrFail($timetable->school_id);

        $tenancy->asSchool($school, fn () => $generator->run($timetable));
    }

    public function failed(Throwable $e): void
    {
        Log::error('Timetable generation failed', ['timetable_id' => $this->timetableId, 'error' => $e->getMessage()]);

        app(TenantManager::class)->withoutScope(fn () => Timetable::query()
            ->whereKey($this->timetableId)
            ->whereIn('status', [TimetableStatus::QUEUED, TimetableStatus::GENERATING])
            ->update([
                'status' => TimetableStatus::FAILED->value,
                // A query-builder update: the model's array cast does not apply.
                'diagnostics' => json_encode(['message' => 'Generation stopped with an error. Try again; if it keeps failing, contact support.']),
            ]));
    }
}
