<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Resources;

use App\Domain\Resources\Models\TeachingMaterial;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The one way a material's file leaves the server, for the admin app and the portal alike. */
trait ServesMaterialFiles
{
    private const INLINE = ['pdf', 'jpg', 'jpeg', 'png'];

    protected function fileResponse(TeachingMaterial $material): StreamedResponse
    {
        $disk = Storage::disk($material->disk);

        if (! $disk->exists($material->path)) {
            abort(404);
        }

        // Named from the title, slugged: no path characters, quotes or header
        // tricks reach Content-Disposition from what an admin typed.
        $extension = $material->extension();
        $name = (Str::slug($material->title) ?: 'material').'.'.$extension;

        return $disk->response($material->path, $name, [
            'Content-Type' => $material->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], in_array($extension, self::INLINE, true) ? 'inline' : 'attachment');
    }
}
