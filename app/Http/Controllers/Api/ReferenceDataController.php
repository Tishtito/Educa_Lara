<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\Grade;
use App\Domain\Academics\Models\Level;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Stream;
use App\Http\Controllers\Controller;
use App\Http\Resources\AcademicYearResource;
use App\Http\Resources\SchoolClassResource;
use App\Http\Resources\StreamResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The lookup lists a client needs to build any form: which levels, grades,
 * years, streams and classes exist. Before these, a client could only discover
 * class ids by opening an exam's marking sheets.
 *
 * Read-only and open to every role. Levels and grades are platform reference
 * data; the rest are tenant-owned and scoped by TenantScope.
 */
class ReferenceDataController extends Controller
{
    public function levels(): JsonResponse
    {
        $levels = Level::query()->with('grades')->orderBy('sort_order')->get();

        return $this->ok($levels->map(fn (Level $level) => [
            'id' => $level->id,
            'code' => $level->code,
            'name' => $level->name,
            'min_grade' => $level->min_grade,
            'max_grade' => $level->max_grade,
            'grades' => $level->grades->map($this->presentGrade(...))->values(),
        ]));
    }

    public function grades(Request $request): JsonResponse
    {
        $grades = Grade::query()
            ->when($request->integer('level_id'), fn ($q, $id) => $q->where('level_id', $id))
            ->orderBy('ordinal')
            ->get();

        return $this->ok($grades->map($this->presentGrade(...)));
    }

    public function academicYears(): JsonResponse
    {
        $years = AcademicYear::query()->with('terms')->orderByDesc('starts_on')->orderByDesc('name')->get();

        return $this->ok(AcademicYearResource::collection($years)->resolve());
    }

    public function streams(): JsonResponse
    {
        $streams = Stream::query()->withCount('classes')->orderBy('sort_order')->orderBy('name')->get();

        return $this->ok(StreamResource::collection($streams)->resolve());
    }

    public function classes(Request $request): JsonResponse
    {
        $currentYearId = $request->boolean('with_counts')
            ? AcademicYear::query()->where('is_current', true)->value('id')
            : null;

        $classes = SchoolClass::query()
            ->with(['grade', 'stream'])
            ->join('grades', 'grades.id', '=', 'classes.grade_id')
            ->select('classes.*')
            ->when($request->boolean('with_counts'), fn ($q) => $q->withCount([
                'enrolments as students_count' => fn ($q) => $q->where('academic_year_id', $currentYearId ?? 0)->where('status', 'active'),
            ]))
            ->when($request->integer('grade_id'), fn ($q, $id) => $q->where('classes.grade_id', $id))
            ->when($request->integer('level_id'), fn ($q, $id) => $q->where('grades.level_id', $id))
            ->when($request->has('active'), fn ($q) => $q->where('classes.is_active', $request->boolean('active')))
            ->orderBy('grades.ordinal')
            ->orderBy('classes.name')
            ->get();

        return $this->ok(SchoolClassResource::collection($classes)->resolve());
    }

    private function presentGrade(Grade $grade): array
    {
        return [
            'id' => $grade->id,
            'level_id' => $grade->level_id,
            'code' => $grade->code,
            'name' => $grade->name,
            'ordinal' => $grade->ordinal,
        ];
    }

    private function ok(iterable $data): JsonResponse
    {
        return response()->json(['success' => true, 'data' => collect($data)->values()]);
    }
}
