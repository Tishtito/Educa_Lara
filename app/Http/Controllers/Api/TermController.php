<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Academics\Models\Term;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reporting\UpdateTermRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Term dates and feeding fee. Report cards print the term's closing date, the
 * next term's opening date and the next term's feeding amount from here.
 */
class TermController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $terms = Term::query()
            ->with('academicYear')
            ->when($request->integer('academic_year_id'), fn ($q, $id) => $q->where('academic_year_id', $id))
            ->orderBy('academic_year_id')
            ->orderBy('term_number')
            ->get();

        return response()->json(['success' => true, 'data' => $terms->map($this->present(...))->values()]);
    }

    public function update(UpdateTermRequest $request, Term $term): JsonResponse
    {
        $term->fill($request->validated())->save();

        return response()->json(['success' => true, 'data' => $this->present($term->fresh('academicYear'))]);
    }

    private function present(Term $term): array
    {
        return [
            'id' => $term->id,
            'academic_year_id' => $term->academic_year_id,
            'academic_year' => $term->academicYear?->name,
            'term_number' => $term->term_number,
            'name' => $term->name,
            'starts_on' => $term->starts_on?->toDateString(),
            'ends_on' => $term->ends_on?->toDateString(),
            'feeding_fee' => $term->feeding_fee === null ? null : (float) $term->feeding_fee,
            'is_current' => $term->is_current,
        ];
    }
}
