<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\People;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\People\Services\PromotionService;
use App\Domain\People\Services\StudentImportService;
use App\Domain\Tenancy\TenantManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Bulk pupil operations: admitting a spreadsheet of pupils, and end-of-year promotion. */
class StudentImportController extends Controller
{
    public function __construct(private readonly TenantManager $tenancy) {}

    /**
     * POST /students/import {academic_year_id?, dry_run, rows: [{assessment_no, first_name|name, …, class}]}
     * The client parses the spreadsheet; the server validates and admits.
     */
    public function import(Request $request, StudentImportService $imports): JsonResponse
    {
        $schoolId = $this->tenancy->currentOrFail()->getKey();
        $data = $request->validate([
            'academic_year_id' => ['sometimes', 'integer', Rule::exists('academic_years', 'id')->where('school_id', $schoolId)],
            'dry_run' => ['required', 'boolean'],
            'rows' => ['present', 'array', 'max:'.StudentImportService::MAX_ROWS],
            'rows.*' => ['array'],
        ]);
        $yearId = $data['academic_year_id'] ?? AcademicYear::query()->where('is_current', true)->value('id');

        if ($yearId === null) {
            return response()->json(['success' => false, 'message' => 'Set a current academic year before importing pupils.'], 409);
        }

        $result = $imports->import($schoolId, (int) $yearId, $data['rows'], (bool) $data['dry_run']);

        return response()->json(['success' => true, 'data' => $result], $result['committed'] ? 201 : 200);
    }

    /** GET /promotions/preview?from_year_id=&to_year_id= */
    public function previewPromotion(Request $request, PromotionService $promotions): JsonResponse
    {
        [$from, $to] = $this->years($request);

        return response()->json(['success' => true, 'data' => [
            'from_year' => ['id' => $from->id, 'name' => $from->name],
            'to_year' => ['id' => $to->id, 'name' => $to->name],
            'classes' => $promotions->preview($from, $to),
        ]]);
    }

    /** POST /promotions {from_year_id, to_year_id, dry_run, mappings, excluded_student_ids} */
    public function promote(Request $request, PromotionService $promotions): JsonResponse
    {
        [$from, $to] = $this->years($request);
        $data = $request->validate([
            'dry_run' => ['required', 'boolean'],
            'mappings' => ['required', 'array', 'min:1'],
            'mappings.*.class_id' => ['required', 'integer', 'distinct'],
            'mappings.*.action' => ['required', Rule::in(['promote', 'graduate', 'skip'])],
            'mappings.*.target_class_id' => ['nullable', 'integer'],
            'excluded_student_ids' => ['sometimes', 'array'],
            'excluded_student_ids.*' => ['integer'],
        ]);

        return response()->json(['success' => true, 'data' => $promotions->promote(
            $from, $to, $data['mappings'], $data['excluded_student_ids'] ?? [], (bool) $data['dry_run'],
        )]);
    }

    /** @return array{0: AcademicYear, 1: AcademicYear} */
    private function years(Request $request): array
    {
        $schoolId = $this->tenancy->currentOrFail()->getKey();
        $data = $request->validate([
            'from_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')->where('school_id', $schoolId)],
            'to_year_id' => ['required', 'integer', 'different:from_year_id', Rule::exists('academic_years', 'id')->where('school_id', $schoolId)],
        ]);

        return [AcademicYear::query()->findOrFail($data['from_year_id']), AcademicYear::query()->findOrFail($data['to_year_id'])];
    }
}
