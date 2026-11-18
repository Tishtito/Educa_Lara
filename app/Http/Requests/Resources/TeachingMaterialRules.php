<?php

declare(strict_types=1);

namespace App\Http\Requests\Resources;

use App\Domain\Academics\Models\Grade;
use App\Domain\Curriculum\Models\LevelSubject;
use App\Domain\Tenancy\TenantManager;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Rules shared by the teaching material requests.
 *
 * The file check pairs the name's extension with the type read from the
 * content: a renamed .exe is refused, and so is a PDF renamed .png (it would be
 * served under the wrong name). Older Office files are often detected only as
 * "application/vnd.ms-office" or as an OLE container, and some libmagic builds
 * report newer ones as zip, so those are accepted for their own extensions only.
 */
final class TeachingMaterialRules
{
    private const OLE = ['application/vnd.ms-office', 'application/x-ole-storage', 'application/CDFV2', 'application/cdfv2'];

    private const MIMES = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', ...self::OLE],
        'xls' => ['application/vnd.ms-excel', ...self::OLE],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/mspowerpoint', ...self::OLE],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
    ];

    /** @return list<mixed> */
    public static function file(bool $required): array
    {
        return [
            $required ? 'required' : 'sometimes',
            'file',
            'max:'.(int) config('elimupass.materials.max_kb', 20480),
            function (string $attribute, mixed $value, Closure $fail): void {
                if (! $value instanceof UploadedFile) {
                    return;
                }

                $extension = strtolower($value->getClientOriginalExtension());
                $allowed = self::MIMES[$extension] ?? null;

                if ($allowed === null || ! in_array((string) $value->getMimeType(), $allowed, true)) {
                    $fail('Upload a PDF, Word, PowerPoint or Excel file, or a JPEG or PNG image.');
                }
            },
        ];
    }

    /** @return array<string, list<mixed>> */
    public static function fields(bool $creating): array
    {
        $schoolId = app(TenantManager::class)->id();
        $sometimes = $creating ? 'required' : 'sometimes';

        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:190'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'level_id' => [$sometimes, 'integer', Rule::exists('levels', 'id')],
            'grade_ids' => ['sometimes', 'nullable', 'array', 'max:9'],
            'grade_ids.*' => ['integer', 'distinct', Rule::exists('grades', 'id')],
            // Tenant-owned: the exists rule is not scoped by itself (CLAUDE.md rule 4).
            'level_subject_id' => ['sometimes', 'nullable', 'integer', Rule::exists('level_subjects', 'id')->where('school_id', $schoolId)],
            'status' => ['sometimes', Rule::in(['draft', 'published'])],
        ];
    }

    /**
     * Every grade, and the subject, must belong to the material's section, so
     * a junior secondary file can never be tagged Grade 5.
     */
    public static function sameSection(Validator $validator, ?int $levelId, ?array $gradeIds, ?int $levelSubjectId, ?int $schoolId): void
    {
        if ($levelId === null || $validator->errors()->isNotEmpty()) {
            return;
        }

        if ($gradeIds) {
            $outside = Grade::query()->whereIn('id', $gradeIds)->where('level_id', '!=', $levelId)->exists();
            if ($outside) {
                $validator->errors()->add('grade_ids', 'Choose grades from the material’s own section.');
            }
        }

        if ($levelSubjectId !== null) {
            // Eloquent, so TenantScope applies as well as the explicit school.
            $matches = LevelSubject::query()
                ->whereKey($levelSubjectId)->where('school_id', $schoolId)->where('level_id', $levelId)->exists();
            if (! $matches) {
                $validator->errors()->add('level_subject_id', 'Choose a subject taught in the material’s own section.');
            }
        }
    }
}
