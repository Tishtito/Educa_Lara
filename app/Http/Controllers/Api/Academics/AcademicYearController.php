<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academics;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Services\AcademicCalendar;
use App\Domain\Tenancy\TenantManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\Academics\StoreAcademicYearRequest;
use App\Http\Requests\Academics\UpdateAcademicYearRequest;
use App\Http\Resources\AcademicYearResource;
use Illuminate\Http\JsonResponse;

/** Creating and maintaining academic years. Listing is GET /academic-years (ReferenceDataController). */
class AcademicYearController extends Controller
{
    public function __construct(
        private readonly AcademicCalendar $calendar,
        private readonly TenantManager $tenancy,
    ) {}

    public function store(StoreAcademicYearRequest $request): JsonResponse
    {
        $data = $request->validated();

        $year = $this->calendar->createYear(
            $this->tenancy->currentOrFail()->getKey(),
            $data,
            $data['terms'] ?? [],
        );

        return response()->json(['success' => true, 'data' => new AcademicYearResource($year)], 201);
    }

    public function update(UpdateAcademicYearRequest $request, AcademicYear $academicYear): JsonResponse
    {
        $academicYear->fill($request->validated())->save();

        return response()->json(['success' => true, 'data' => new AcademicYearResource($academicYear->fresh('terms'))]);
    }

    public function makeCurrent(AcademicYear $academicYear): JsonResponse
    {
        return response()->json(['success' => true, 'data' => new AcademicYearResource($this->calendar->makeYearCurrent($academicYear))]);
    }

    public function destroy(AcademicYear $academicYear): JsonResponse
    {
        $this->calendar->deleteYear($academicYear);

        return response()->json(['success' => true, 'message' => 'Academic year deleted.']);
    }
}
