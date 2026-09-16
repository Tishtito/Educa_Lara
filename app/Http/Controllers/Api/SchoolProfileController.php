<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\TenantManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The school's identity as printed on report cards. Legacy hardcoded it into
 * each template — and two templates disagreed about which school they were.
 */
class SchoolProfileController extends Controller
{
    public function __construct(private readonly TenantManager $tenancy) {}

    public function show(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->present($this->school())]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:190'],
            'short_name' => ['sometimes', 'nullable', 'string', 'max:60'],
            'motto' => ['sometimes', 'nullable', 'string', 'max:190'],
            'county' => ['sometimes', 'nullable', 'string', 'max:80'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],
        ]);

        $school = $this->school();
        $school->fill($data)->save();

        return response()->json(['success' => true, 'data' => $this->present($school)]);
    }

    /** POST /school/logo (multipart "logo"). PNG or JPEG, as dompdf embeds those reliably. */
    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:512', 'dimensions:min_width=64,min_height=64,max_width=2000,max_height=2000'],
        ], [
            'logo.max' => 'The logo must be smaller than 512 KB.',
            'logo.mimes' => 'Upload the logo as a PNG or JPEG image.',
        ]);

        $school = $this->school();
        $old = $school->logo_path;

        // Per-school folder and a random name: a new upload never collides with a
        // cached copy of the old one, and paths do not reveal other schools.
        $path = $request->file('logo')->storeAs(
            "schools/{$school->uuid}",
            'logo-'.Str::lower(Str::random(12)).'.'.$request->file('logo')->extension(),
            'public',
        );

        $school->forceFill(['logo_path' => $path])->save();

        if ($old && $old !== $path) {
            Storage::disk('public')->delete($old);
        }

        return response()->json(['success' => true, 'data' => $this->present($school)]);
    }

    public function deleteLogo(): JsonResponse
    {
        $school = $this->school();

        if ($school->logo_path) {
            Storage::disk('public')->delete($school->logo_path);
            $school->forceFill(['logo_path' => null])->save();
        }

        return response()->json(['success' => true, 'data' => $this->present($school)]);
    }

    /** GET /school/logo — the image itself, for previews in the app. */
    public function logo(): Response
    {
        $path = $this->school()->logo_path;

        if (! $path || ! Storage::disk('public')->exists($path)) {
            abort(404);
        }

        return Storage::disk('public')->response($path, null, ['Cache-Control' => 'private, max-age=300']);
    }

    private function school(): School
    {
        return School::query()->findOrFail($this->tenancy->currentOrFail()->getKey());
    }

    private function present(School $school): array
    {
        return [
            'uuid' => $school->uuid,
            'slug' => $school->slug,
            'name' => $school->name,
            'short_name' => $school->short_name,
            'motto' => $school->motto,
            'county' => $school->county,
            'address' => $school->address,
            'phone' => $school->phone,
            'email' => $school->email,
            'has_logo' => (bool) $school->logo_path,
            'status' => $school->status,
        ];
    }
}
