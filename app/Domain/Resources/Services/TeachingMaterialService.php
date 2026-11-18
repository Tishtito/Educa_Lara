<?php

declare(strict_types=1);

namespace App\Domain\Resources\Services;

use App\Domain\People\Models\User;
use App\Domain\Resources\Enums\MaterialStatus;
use App\Domain\Resources\Models\TeachingMaterial;
use App\Domain\Tenancy\Models\School;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Writes teaching materials and their files. Controllers stay thin; every
 * change to a material's file goes through here so the row and the disk
 * cannot disagree.
 */
class TeachingMaterialService
{
    /** Extensions a material may have; the request's mimes rule checks the content matches. */
    public const EXTENSIONS = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];

    /**
     * @param  array{title?: ?string, description?: ?string, level_id: int, level_subject_id?: ?int, grade_ids?: list<int>, status?: string}  $data
     */
    public function store(School $school, User $by, UploadedFile $file, array $data): TeachingMaterial
    {
        $uuid = (string) Str::uuid();
        $stored = $this->putFile($school, $uuid, $file);

        try {
            return DB::transaction(function () use ($school, $by, $file, $data, $uuid, $stored) {
                $status = MaterialStatus::from($data['status'] ?? MaterialStatus::Published->value);

                $material = TeachingMaterial::query()->create([
                    'uuid' => $uuid,
                    'school_id' => $school->id,
                    'level_id' => $data['level_id'],
                    'level_subject_id' => $data['level_subject_id'] ?? null,
                    'title' => $this->title($data['title'] ?? null, $file),
                    'description' => $data['description'] ?? null,
                    ...$stored,
                    'status' => $status,
                    'published_at' => $status === MaterialStatus::Published ? now() : null,
                    'uploaded_by' => $by->id,
                    'updated_by' => $by->id,
                ]);

                $material->grades()->sync($data['grade_ids'] ?? []);

                return $material;
            });
        } catch (\Throwable $e) {
            Storage::disk($stored['disk'])->delete($stored['path']);
            throw $e;
        }
    }

    /** @param  array<string, mixed>  $data  validated fields; grade_ids replaces the grades when present */
    public function update(TeachingMaterial $material, User $by, array $data): TeachingMaterial
    {
        return DB::transaction(function () use ($material, $by, $data) {
            $fields = collect($data)->only(['title', 'description', 'level_id', 'level_subject_id'])->all();

            if (array_key_exists('status', $data)) {
                $status = MaterialStatus::from($data['status']);
                $fields['status'] = $status;
                // First publication only: re-publishing after a hide must not
                // show the file as "new" to teachers again.
                if ($status === MaterialStatus::Published && $material->published_at === null) {
                    $fields['published_at'] = now();
                }
            }

            // A change of section drops a subject from the old section unless one was given.
            if (isset($fields['level_id']) && (int) $fields['level_id'] !== $material->level_id && ! array_key_exists('level_subject_id', $fields)) {
                $fields['level_subject_id'] = null;
            }

            $material->fill($fields + ['updated_by' => $by->id])->save();

            if (array_key_exists('grade_ids', $data)) {
                $material->grades()->sync($data['grade_ids'] ?? []);
            }

            return $material->refresh();
        });
    }

    /**
     * New file, same record. The new file is written first and the old one
     * removed only after the row commits, so a failure never leaves a material
     * pointing at nothing.
     */
    public function replaceFile(TeachingMaterial $material, User $by, UploadedFile $file): TeachingMaterial
    {
        $school = School::query()->findOrFail($material->school_id);
        $old = ['disk' => $material->disk, 'path' => $material->path];
        $stored = $this->putFile($school, $material->uuid.'-'.Str::lower(Str::random(8)), $file);

        try {
            DB::transaction(function () use ($material, $by, $file, $stored, $old): void {
                $material->fill([...$stored, 'original_name' => $this->originalName($file), 'updated_by' => $by->id])->save();

                DB::afterCommit(fn () => Storage::disk($old['disk'])->delete($old['path']));
            });
        } catch (\Throwable $e) {
            Storage::disk($stored['disk'])->delete($stored['path']);
            throw $e;
        }

        return $material->refresh();
    }

    /** Soft delete: the file stays until materials:prune, so the delete can be undone. */
    public function delete(TeachingMaterial $material, User $by): void
    {
        $material->forceFill(['updated_by' => $by->id])->save();
        $material->delete();
    }

    public function restore(TeachingMaterial $material, User $by): TeachingMaterial
    {
        $material->restore();
        $material->forceFill(['updated_by' => $by->id])->save();

        return $material;
    }

    /** Remove the row and its file for good (materials:prune). */
    public function purge(TeachingMaterial $material): void
    {
        Storage::disk($material->disk)->delete($material->path);
        $material->forceDelete();
    }

    /** Another live material of the school with the same content, for the "already uploaded" warning. */
    public function duplicateOf(TeachingMaterial $material): ?TeachingMaterial
    {
        return TeachingMaterial::query()
            ->where('sha256', $material->sha256)
            ->whereKeyNot($material->getKey())
            ->oldest('id')
            ->first();
    }

    /** @return array{disk: string, path: string, original_name: string, mime_type: string, size_bytes: int, sha256: string} */
    private function putFile(School $school, string $name, UploadedFile $file): array
    {
        $disk = (string) config('elimupass.materials.disk', 'local');
        $extension = $this->extension($file);

        // Per-school folder, file named by uuid: the original name lives only in
        // the database, so paths never carry what a teacher typed.
        $path = $file->storeAs("schools/{$school->uuid}/materials", "{$name}.{$extension}", $disk);

        if ($path === false) {
            throw new \RuntimeException('The file could not be stored.');
        }

        return [
            'disk' => $disk,
            'path' => $path,
            'original_name' => $this->originalName($file),
            'mime_type' => (string) ($file->getMimeType() ?: 'application/octet-stream'),
            'size_bytes' => (int) $file->getSize(),
            'sha256' => (string) hash_file('sha256', $file->getRealPath()),
        ];
    }

    private function extension(UploadedFile $file): string
    {
        $client = strtolower($file->getClientOriginalExtension());

        // The client's extension when it is an allowed one (the mimes rule has
        // checked the content agrees); otherwise what the content says.
        return in_array($client, self::EXTENSIONS, true) ? $client : (string) ($file->guessExtension() ?? 'bin');
    }

    private function originalName(UploadedFile $file): string
    {
        return Str::limit(basename(str_replace('\\', '/', $file->getClientOriginalName())), 250, '');
    }

    private function title(?string $title, UploadedFile $file): string
    {
        $title = trim((string) $title);

        if ($title === '') {
            $title = trim(pathinfo($this->originalName($file), PATHINFO_FILENAME)) ?: 'Untitled';
        }

        return Str::limit($title, 190, '');
    }
}
