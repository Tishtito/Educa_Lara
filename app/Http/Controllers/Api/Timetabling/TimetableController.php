<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Timetabling;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Term;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\TenantManager;
use App\Domain\Timetabling\Models\Timetable;
use App\Domain\Timetabling\Services\ReadinessCheck;
use App\Domain\Timetabling\Services\TimetableGenerator;
use App\Domain\Timetabling\Services\TimetableInputBuilder;
use App\Domain\Timetabling\Services\TimetableLifecycle;
use App\Domain\Timetabling\Services\TimetableViews;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 *   GET    /timetables/readiness           what blocks generation
 *   GET    /timetables                     the year's timetables
 *   POST   /timetables                     -> 202, generated on a worker
 *   GET    /timetables/{t}                 status (poll while queued/generating)
 *   GET    /timetables/{t}/classes/{c}     one class's week
 *   GET    /timetables/{t}/teachers/{u}    one teacher's week
 *   POST   /timetables/{t}/publish | duplicate,  DELETE /timetables/{t}
 */
class TimetableController extends Controller
{
    public function __construct(
        private readonly TimetableViews $views,
        private readonly TenantManager $tenancy,
    ) {}

    public function readiness(Request $request, TimetableInputBuilder $builder, ReadinessCheck $check): JsonResponse
    {
        $year = $this->year($request);

        if ($year === null) {
            return response()->json(['success' => true, 'data' => ['ready' => false, 'issues' => [[
                'level' => ReadinessCheck::ERROR, 'code' => 'no_year', 'message' => 'Set up the current academic year first.', 'fix' => 'calendar',
            ]], 'summary' => ['classes' => 0, 'lessons' => 0, 'teachers' => 0]]]);
        }

        return response()->json(['success' => true, 'data' => $check->check($builder->plan($year))]);
    }

    public function index(Request $request): JsonResponse
    {
        $year = $this->year($request);

        $timetables = Timetable::query()
            ->where('academic_year_id', $year?->id ?? 0)
            ->withCount('lessons')
            ->with(['academicYear', 'term', 'generatedBy'])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json(['success' => true, 'data' => $timetables->map(fn (Timetable $t) => $this->views->summary($t))->values()]);
    }

    /** POST /timetables {academic_year_id?, term_id?, name?, keep_locked_from?} */
    public function store(Request $request, TimetableGenerator $generator): JsonResponse
    {
        $schoolId = $this->schoolId();
        $data = $request->validate([
            'academic_year_id' => ['sometimes', 'integer', Rule::exists('academic_years', 'id')->where('school_id', $schoolId)],
            'term_id' => ['sometimes', 'nullable', 'integer', Rule::exists('terms', 'id')->where('school_id', $schoolId)],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'keep_locked_from' => ['sometimes', 'nullable', 'integer', Rule::exists('timetables', 'id')->where('school_id', $schoolId)],
        ]);

        $year = $this->year($request);
        abort_if($year === null, 422, 'Set up the current academic year first.');

        $term = isset($data['term_id']) ? Term::query()->where('academic_year_id', $year->id)->find($data['term_id']) : null;
        $source = isset($data['keep_locked_from']) ? Timetable::query()->where('academic_year_id', $year->id)->find($data['keep_locked_from']) : null;

        $queued = $generator->queue($year, $term, $data['name'] ?? null, $source, $request->user());

        if ($queued['timetable'] === null) {
            return response()->json([
                'success' => false,
                'message' => 'The school’s set-up is not ready for a timetable.',
                'readiness' => $queued['readiness'],
            ], 422);
        }

        return response()->json(['success' => true, 'data' => $this->views->summary($queued['timetable'])], 202);
    }

    public function show(Timetable $timetable): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->views->detail($timetable)]);
    }

    public function forClass(Timetable $timetable, SchoolClass $schoolClass): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->views->forClass($timetable, $schoolClass)]);
    }

    public function forTeacher(Timetable $timetable, int $user): JsonResponse
    {
        // Users are not tenant-scoped (sign-in finds them before a tenant
        // exists), so binding {user} would reach other schools' staff.
        $teacher = User::query()->where('school_id', $this->schoolId())->findOrFail($user);

        return response()->json(['success' => true, 'data' => $this->views->forTeacher($timetable, $teacher)]);
    }

    public function publish(Timetable $timetable, TimetableLifecycle $lifecycle): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->views->summary($lifecycle->publish($timetable))]);
    }

    public function duplicate(Request $request, Timetable $timetable, TimetableLifecycle $lifecycle): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->views->summary($lifecycle->duplicate($timetable, $request->user()))], 201);
    }

    public function destroy(Timetable $timetable, TimetableLifecycle $lifecycle): JsonResponse
    {
        $lifecycle->delete($timetable);

        return response()->json(['success' => true]);
    }

    private function year(Request $request): ?AcademicYear
    {
        return $request->integer('academic_year_id')
            ? AcademicYear::query()->find($request->integer('academic_year_id'))
            : AcademicYear::query()->where('is_current', true)->first();
    }

    private function schoolId(): int
    {
        return $this->tenancy->currentOrFail()->getKey();
    }
}
