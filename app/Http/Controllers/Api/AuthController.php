<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Domain\Tenancy\Models\SchoolDomain;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Replaces the three legacy AuthControllers (admin, class-teacher, examiner),
 * which each authenticated against their own table and responded to a successful
 * login with an HTTP redirect to an HTML page rather than JSON.
 *
 * The legacy open POST /signup/register endpoints are deliberately not carried
 * over — anyone could create an admin account on any deployment. Users are
 * created by a school admin.
 */
class AuthController extends Controller
{
    /**
     * Credentials are (school, username, password), not (username, password):
     * users is unique on (school_key, username), so a username identifies a
     * person only within one school.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();
        $requestedKey = $data['school'] ?? null;
        $school = $this->resolveSchool($request, $requestedKey);

        // A school was named but does not exist. Without this, the lookup would
        // fall through to school_key 0 and quietly attempt the request against
        // the platform namespace where superadmins live.
        if ($requestedKey !== null && $requestedKey !== '' && $school === null) {
            throw ValidationException::withMessages([
                'school' => ['No school matches that identifier.'],
            ]);
        }

        $user = User::query()
            ->where('username', $data['username'])
            ->where('school_key', $school?->getKey() ?? 0)
            ->with(['roles', 'school'])
            ->first();

        // One message and one code for "no such user" and "wrong password", so
        // the endpoint cannot be used to enumerate usernames.
        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'username' => ['These credentials do not match our records.'],
            ]);
        }

        if (! $user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'This account has been deactivated. Contact your school administrator.',
            ], 403);
        }

        if ($user->school !== null && $user->school->status === 'suspended') {
            return response()->json([
                'success' => false,
                'message' => 'This school account is suspended.',
            ], 403);
        }

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        $token = $user->createToken($data['device_name'] ?? 'api')->plainTextToken;

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $token,
                'user' => new UserResource($user),
            ],
        ]);
    }

    /** Revokes only the token that made this request, not every session. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Signed out.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['roles', 'school']);

        return response()->json([
            'success' => true,
            'data' => new UserResource($user),
        ]);
    }

    /**
     * The school a login attempt belongs to: an explicit slug/uuid if given,
     * otherwise the school that has claimed this hostname. Null means the
     * platform tenant (school_key 0), where superadmins live.
     */
    private function resolveSchool(Request $request, ?string $key): ?School
    {
        if ($key !== null && $key !== '') {
            return School::query()
                ->where('slug', $key)
                ->orWhere('uuid', $key)
                ->first();
        }

        return SchoolDomain::query()
            ->where('hostname', $request->getHost())
            ->with('school')
            ->first()?->school;
    }
}
