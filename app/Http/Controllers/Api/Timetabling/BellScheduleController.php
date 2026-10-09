<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Timetabling;

use App\Domain\Academics\Models\Level;
use App\Domain\Timetabling\Models\BellPeriod;
use App\Domain\Timetabling\Models\BellSchedule;
use App\Domain\Timetabling\Services\BellScheduleService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The school day per level: GET/PUT /timetable/bell-schedules. */
class BellScheduleController extends Controller
{
    public function __construct(private readonly BellScheduleService $schedules) {}

    public function index(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => [
            'levels' => Level::query()->orderBy('sort_order')->get()->map(fn (Level $l) => ['id' => $l->id, 'code' => $l->code, 'name' => $l->name])->values(),
            'schedules' => $this->schedules->all()->map(fn (BellSchedule $s) => $this->present($s))->values(),
        ]]);
    }

    /** PUT /timetable/bell-schedules {level_id|null, name, days, periods: [{kind, label?, starts_at, ends_at}]} */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'level_id' => ['present', 'nullable', 'integer', Rule::exists('levels', 'id')],
            'name' => ['required', 'string', 'max:80'],
            'days' => ['required', 'array', 'min:1', 'max:7'],
            'days.*' => ['integer', 'between:1,7', 'distinct'],
            'periods' => ['required', 'array', 'min:1', 'max:20'],
            'periods.*.kind' => ['required', Rule::in(BellPeriod::KINDS)],
            'periods.*.label' => ['nullable', 'string', 'max:60'],
            'periods.*.starts_at' => ['required', 'date_format:H:i'],
            'periods.*.ends_at' => ['required', 'date_format:H:i'],
        ]);

        $schedule = $this->schedules->save(isset($data['level_id']) ? (int) $data['level_id'] : null, $data['name'], $data['days'], $data['periods']);

        return response()->json(['success' => true, 'data' => $this->present($schedule)]);
    }

    /** POST /timetable/bell-schedules/defaults — a starting day for every level without one. */
    public function defaults(): JsonResponse
    {
        $created = $this->schedules->applyDefaults();

        return response()->json(['success' => true, 'data' => [
            'created' => $created,
            'schedules' => $this->schedules->all()->map(fn (BellSchedule $s) => $this->present($s))->values(),
        ]]);
    }

    public function destroy(BellSchedule $bellSchedule): JsonResponse
    {
        $bellSchedule->delete();

        return response()->json(['success' => true]);
    }

    private function present(BellSchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'level_id' => $schedule->level_id,
            'name' => $schedule->name,
            'days' => $schedule->dayNumbers(),
            'periods' => $schedule->periods->map(fn (BellPeriod $p) => [
                'sequence' => $p->sequence,
                'kind' => $p->kind,
                'label' => $p->label,
                'starts_at' => substr((string) $p->starts_at, 0, 5),
                'ends_at' => substr((string) $p->ends_at, 0, 5),
            ])->values(),
            'lessons_per_day' => $schedule->periods->where('kind', BellPeriod::LESSON)->count(),
        ];
    }
}
