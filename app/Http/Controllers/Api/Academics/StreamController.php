<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Academics;

use App\Domain\Academics\Exceptions\SetupConflict;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Stream;
use App\Domain\Tenancy\TenantManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\Academics\StreamRequest;
use App\Http\Resources\StreamResource;
use Illuminate\Http\JsonResponse;

/** Streams ("Blue", "East"). Legacy baked the stream into a class-name string like "7_east". */
class StreamController extends Controller
{
    public function store(StreamRequest $request, TenantManager $tenancy): JsonResponse
    {
        $stream = Stream::create($request->validated() + ['school_id' => $tenancy->currentOrFail()->getKey()]);

        return response()->json(['success' => true, 'data' => new StreamResource($stream)], 201);
    }

    public function update(StreamRequest $request, Stream $stream): JsonResponse
    {
        $stream->fill($request->validated())->save();

        return response()->json(['success' => true, 'data' => new StreamResource($stream)]);
    }

    public function destroy(Stream $stream): JsonResponse
    {
        if (SchoolClass::query()->withTrashed()->where('stream_id', $stream->id)->exists()) {
            throw SetupConflict::cannotDelete("Stream {$stream->name}", 'classes belong to it', 'Delete or move those classes first.');
        }

        $stream->delete();

        return response()->json(['success' => true, 'message' => 'Stream deleted.']);
    }
}
