<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reporting\Services\ReportCardBuilder;
use App\Domain\Tenancy\Models\SchoolSetting;
use App\Domain\Tenancy\TenantManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reporting\ReportSettingsRequest;
use Illuminate\Http\JsonResponse;

/**
 * GET/PUT /api/school/report-card-settings — the head teacher's name and the
 * footer printed on every card.
 *
 * SchoolSetting is NOT tenant-scoped (no BelongsToSchool), so every query here
 * filters by the resolved school explicitly.
 */
class ReportCardSettingsController extends Controller
{
    private const KEYS = [
        'head_teacher_name' => 'report_card.head_teacher_name',
        'footer' => 'report_card.footer',
    ];

    public function __construct(private readonly TenantManager $tenancy) {}

    public function show(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->current()]);
    }

    public function update(ReportSettingsRequest $request): JsonResponse
    {
        $schoolId = $this->tenancy->currentOrFail()->getKey();

        foreach (self::KEYS as $field => $key) {
            $value = $request->validated($field);

            if (blank($value)) {
                SchoolSetting::query()->where('school_id', $schoolId)->where('key', $key)->delete();
            } else {
                SchoolSetting::query()->updateOrCreate(
                    ['school_id' => $schoolId, 'key' => $key],
                    ['value' => trim((string) $value)],
                );
            }
        }

        return response()->json(['success' => true, 'data' => $this->current()]);
    }

    private function current(): array
    {
        $settings = SchoolSetting::query()
            ->where('school_id', $this->tenancy->currentOrFail()->getKey())
            ->whereIn('key', self::KEYS)
            ->pluck('value', 'key');

        return [
            'head_teacher_name' => $settings[self::KEYS['head_teacher_name']] ?? null,
            'footer' => $settings[self::KEYS['footer']] ?? null,
            'default_footer' => ReportCardBuilder::DEFAULT_FOOTER,
        ];
    }
}
