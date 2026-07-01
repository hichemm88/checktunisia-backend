<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)
            ->whereNull('deleted_at')
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->status !== 'active') {
            return response()->json([
                'data'   => null,
                'errors' => [['code' => 'AUTH_ACCOUNT_SUSPENDED', 'message' => 'Your account is suspended.', 'field' => null]],
            ], 403);
        }

        $user->update(['last_login_at' => now()]);

        $token = $user->createToken('api-token', ['*'], now()->addDays(30));

        AuditLogger::log('user.login', $user, actor: $user);

        $hotel = $user->isHotelStaff() ? $user->hotel() : null;

        return response()->json([
            'data' => [
                'token'      => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $token->accessToken->expires_at,
                'user'       => [
                    'id'         => $user->id,
                    'email'      => $user->email,
                    'first_name' => $user->first_name,
                    'last_name'  => $user->last_name,
                    'role'       => $user->primary_role,
                    'hotel'      => $hotel ? [
                        'id'                  => $hotel->id,
                        'name'                => $hotel->name,
                        'slug'                => $hotel->slug,
                        'subscription_status' => $hotel->activeSubscription?->status ?? 'none',
                    ] : null,
                    'permissions' => $user->getAllPermissions()->pluck('name'),
                ],
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        AuditLogger::log('user.logout', $request->user());
        $request->user()->currentAccessToken()->delete();
        return response()->json(null, 204);
    }

    public function me(Request $request): JsonResponse
    {
        $user  = $request->user()->load(['roles', 'hotels']);
        $hotel = $user->isHotelStaff() ? $user->hotel() : null;

        return response()->json([
            'data' => [
                'id'          => $user->id,
                'email'       => $user->email,
                'first_name'  => $user->first_name,
                'last_name'   => $user->last_name,
                'phone'       => $user->phone,
                'role'        => $user->primary_role,
                'hotel'       => $hotel ? [
                    'id'                  => $hotel->id,
                    'name'                => $hotel->name,
                    'slug'                => $hotel->slug,
                    'subscription_status' => $hotel->activeSubscription?->status ?? 'none',
                    'subscription_expires_at' => $hotel->activeSubscription?->expires_at,
                ] : null,
                'permissions' => $user->getAllPermissions()->pluck('name'),
            ],
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink($request->only('email'));

        // Always return 200 even if email not found (security)
        return response()->json(['data' => ['message' => 'If this email exists, a reset link has been sent.']]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token'                 => ['required'],
            'email'                 => ['required', 'email'],
            'password'              => ['required', 'min:8', 'confirmed'],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        return response()->json(['data' => ['message' => 'Password reset successfully.']]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name'  => ['sometimes', 'string', 'max:100'],
            'phone'      => ['sometimes', 'nullable', 'string', 'max:30'],
        ]);

        $old = $user->only(['first_name', 'last_name', 'phone']);
        $user->update($validated);
        AuditLogger::log('profile.updated', $user, $old, $user->fresh()->only(['first_name', 'last_name', 'phone']));

        return response()->json(['data' => $user->fresh()->only(['id', 'email', 'first_name', 'last_name', 'phone'])]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required'],
            'password'         => ['required', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();
        if (!Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['Current password is incorrect.']]);
        }

        $user->update(['password' => Hash::make($validated['password'])]);
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        AuditLogger::log('profile.password_changed', $user);

        return response()->json(['data' => ['message' => 'Password updated successfully.']]);
    }
}
