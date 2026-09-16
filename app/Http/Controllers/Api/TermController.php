<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Academics\Models\Term;
use App\Domain\Academics\Services\AcademicCalendar;
use App\Domain\Tenancy\TenantManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\Academics\StoreTermRequest;
use App\Http\Requests\Reporting\UpdateTermRequest;
use App\Http\Resources\TermResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Term dates and feeding fee. Report cards print the term's closing date, the
 * next term's opening date and the next term's feeding amount from here.
 */
class TermController extends Controller
{
    public function __construct(private readonly AcademicCalendar $calendar) {}

    public function index(Request $request): JsonResponse
    {
        $terms = Term::query()
            ->with('academicYear')
            ->when($request->integer('academic_year_id'), fn ($q, $id) => $q->where('academic_year_id', $id))
            ->orderBy('academic_year_id')
            ->orderBy('term_number')
            ->get();

        return response()->json(['success' => true, 'data' => TermResource::collection($terms)->resolve()]);
    }

    /** A term the year was created without (years are created with three by default). */
    public function store(StoreTermRequest $request, TenantManager $tenancy): JsonResponse
    {
        $term = Term::create($request->validated() + [
            'school_id' => $tenancy->currentOrFail()->getKey(),
            'is_current' => false,
        ]);

        return response()->json(['success' => true, 'data' => new TermResource($term->load('academicYear'))], 201);
    }

    public function update(UpdateTermRequest $request, Term $term): JsonResponse
    {
        $term->fill($request->validated())->save();

        return response()->json(['success' => true, 'data' => new TermResource($term->fresh('academicYear'))]);
    }

    public function makeCurrent(Term $term): JsonResponse
    {
        return response()->json(['success' => true, 'data' => new TermResource($this->calendar->makeTermCurrent($term))]);
    }
}
