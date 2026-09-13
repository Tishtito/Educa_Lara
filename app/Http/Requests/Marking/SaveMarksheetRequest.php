<?php

declare(strict_types=1);

namespace App\Http\Requests\Marking;

class SaveMarksheetRequest extends SheetRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            // A class of 60 with a three-paper subject is 180 cells; leave room.
            'marks' => ['required', 'array', 'min:1', 'max:1000'],
            'marks.*.enrolment_id' => ['required', 'integer'],
            'marks.*.subject_paper_id' => ['required', 'integer'],
            // present + nullable: null is meaningful (it clears a mark), so the
            // key must be sent rather than omitted by accident.
            'marks.*.raw_score' => ['present', 'nullable', 'numeric', 'min:0', 'max:9999.99', 'decimal:0,2'],
            'marks.*.is_absent' => ['sometimes', 'boolean'],
        ];
    }

    /** @return list<array{enrolment_id: int, subject_paper_id: int, raw_score: float|null, is_absent: bool}> */
    public function marks(): array
    {
        return array_values(array_map(fn (array $row) => [
            'enrolment_id' => (int) $row['enrolment_id'],
            'subject_paper_id' => (int) $row['subject_paper_id'],
            'raw_score' => $row['raw_score'] === null ? null : (float) $row['raw_score'],
            'is_absent' => (bool) ($row['is_absent'] ?? false),
        ], $this->validated('marks')));
    }
}
