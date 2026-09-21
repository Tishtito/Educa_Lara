<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\People\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Student */
class StudentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'assessment_no' => $this->assessment_no,
            'upi' => $this->upi,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'gender' => $this->gender,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'guardian_name' => $this->guardian_name,
            'guardian_phone' => $this->guardian_phone,
            'status' => $this->status,
            // The enrolment for the year being viewed (the controller loads it as `enrolment`).
            'enrolment' => $this->whenLoaded('enrolment', fn () => $this->enrolment ? [
                'id' => $this->enrolment->id,
                'academic_year_id' => $this->enrolment->academic_year_id,
                'class_id' => $this->enrolment->class_id,
                'class_name' => $this->enrolment->schoolClass?->name,
                'status' => $this->enrolment->status,
            ] : null),
            'enrolments' => $this->whenLoaded('enrolments', fn () => $this->enrolments->map(fn ($e) => [
                'id' => $e->id,
                'academic_year_id' => $e->academic_year_id,
                'academic_year' => $e->academicYear?->name,
                'class_id' => $e->class_id,
                'class_name' => $e->schoolClass?->name,
                'status' => $e->status,
                'started_on' => $e->started_on?->toDateString(),
                'ended_on' => $e->ended_on?->toDateString(),
            ])->values()),
        ];
    }
}
