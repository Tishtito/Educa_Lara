<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Reporting\Enums\ReportTemplate;
use App\Domain\Reporting\ReportCardSample;
use App\Domain\Reporting\Services\ReportCardBuilder;
use App\Domain\Reporting\Services\ReportCardRenderer;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Models\SchoolSetting;
use App\Domain\Tenancy\TenantManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reporting\ReportLayoutRequest;
use App\Http\Requests\Reporting\ReportSettingsRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET/PUT /api/school/report-card-settings — the head teacher's name and the
 * footer printed on every card, and the template (design) cards are printed in.
 *
 * GET /api/school/report-card-templates/{template}/preview?layout= — a sample
 * card in that template, as a PDF, so a school can look before it chooses.
 *
 * SchoolSetting is NOT tenant-scoped (no BelongsToSchool), so every query here
 * filters by the resolved school explicitly.
 */
class ReportCardSettingsController extends Controller
{
    private const TEMPLATE_KEY = 'report_card.template';

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

        // Only when sent (the rule is `sometimes`). Stored even when it is the
        // default, so the school's choice is on record rather than implied.
        $template = $request->validated('template');

        if ($template !== null) {
            SchoolSetting::query()->updateOrCreate(
                ['school_id' => $schoolId, 'key' => self::TEMPLATE_KEY],
                ['value' => ReportTemplate::from($template)->value],
            );
        }

        return response()->json(['success' => true, 'data' => $this->current()]);
    }

    /**
     * A made-up pupil under the school's own name and logo (ReportCardSample):
     * no pupil data is read, so this needs no exam, no class and no
     * subscription. An unknown {template} never gets here — the enum binding
     * answers 404.
     */
    public function preview(ReportLayoutRequest $request, ReportTemplate $template, ReportCardSample $sample, ReportCardRenderer $renderer): Response
    {
        $school = School::query()->with('settings')->findOrFail($this->tenancy->currentOrFail()->getKey());
        $layout = $request->layout();

        return response($renderer->pdf([$sample->card($school, $template, $layout)]), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="report-card-'.$template->value.'-'.$layout->value.'-sample.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function current(): array
    {
        $settings = SchoolSetting::query()
            ->where('school_id', $this->tenancy->currentOrFail()->getKey())
            ->whereIn('key', [...array_values(self::KEYS), self::TEMPLATE_KEY])
            ->pluck('value', 'key');

        return [
            'head_teacher_name' => $settings[self::KEYS['head_teacher_name']] ?? null,
            'footer' => $settings[self::KEYS['footer']] ?? null,
            'default_footer' => ReportCardBuilder::DEFAULT_FOOTER,
            // The effective template, as ReportCardBuilder reads it.
            'template' => ReportTemplate::fromSetting($settings[self::TEMPLATE_KEY] ?? null)->value,
            'templates' => ReportTemplate::options(),
        ];
    }
}
