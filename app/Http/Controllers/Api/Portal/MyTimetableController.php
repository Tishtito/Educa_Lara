<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Portal;

use App\Domain\Academics\Models\SchoolClass;
use App\Domain\People\ClassTeachingAccess;
use App\Domain\Timetabling\Services\TimetableViews;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The staff portal's timetable: only ever the published one, so a teacher
 * never sees a draft the admin is still moving lessons in.
 */
class MyTimetableController extends Controller
{
    public function __construct(private readonly TimetableViews $views) {}

    /** GET /me/timetable — my lessons this week. data is null until one is published. */
    public function mine(Request $request): JsonResponse
    {
        $timetable = $this->views->current();

        return response()->json(['success' => true, 'data' => $timetable === null ? null : $this->views->forTeacher($timetable, $request->user())]);
    }

    /** GET /my/classes/{schoolClass}/timetable — the week of a class I am class teacher of. */
    public function forClass(Request $request, SchoolClass $schoolClass, ClassTeachingAccess $access): JsonResponse
    {
        $timetable = $this->views->current();

        if (! $access->teachesClass($request->user(), $schoolClass->id, $timetable?->academic_year_id ?? $access->currentYearId())) {
            return response()->json(['success' => false, 'message' => 'You can only see the timetable of a class you teach.'], 403);
        }

        return response()->json(['success' => true, 'data' => $timetable === null ? null : $this->views->forClass($timetable, $schoolClass)]);
    }
}
