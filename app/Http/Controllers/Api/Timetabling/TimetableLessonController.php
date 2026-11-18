<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Timetabling;

use App\Domain\Timetabling\Models\Timetable;
use App\Domain\Timetabling\Models\TimetableLesson;
use App\Domain\Timetabling\Services\TimetableEditor;
use App\Domain\Timetabling\Services\TimetableViews;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 *   GET   /timetables/{t}/lessons/{l}/options   where the lesson may go
 *   PATCH /timetables/{t}/lessons/{l}           {day, period_sequence} and/or {is_locked}
 *
 * {lesson} is scoped to {timetable} (scopeBindings), so a lesson id from
 * another timetable 404s.
 */
class TimetableLessonController extends Controller
{
    public function __construct(private readonly TimetableEditor $editor) {}

    public function options(Timetable $timetable, TimetableLesson $lesson): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->editor->options($lesson)]);
    }

    public function update(Request $request, Timetable $timetable, TimetableLesson $lesson, TimetableViews $views): JsonResponse
    {
        $data = $request->validate([
            'day' => ['required_with:period_sequence', 'integer', 'between:1,7'],
            'period_sequence' => ['required_with:day', 'integer', 'between:1,30'],
            'is_locked' => ['sometimes', 'boolean'],
        ]);

        $warnings = [];

        if (isset($data['day'])) {
            $warnings = $this->editor->move($lesson, (int) $data['day'], (int) $data['period_sequence']);
        }

        if (array_key_exists('is_locked', $data)) {
            $this->editor->setLocked($lesson->fresh(), (bool) $data['is_locked']);
        }

        return response()->json(['success' => true, 'data' => $views->lesson($lesson->fresh(['schoolClass', 'levelSubject.subject', 'teacher'])), 'warnings' => $warnings]);
    }
}
