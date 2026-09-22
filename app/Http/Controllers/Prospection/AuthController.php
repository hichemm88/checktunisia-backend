<?php

namespace App\Http\Controllers\Prospection;

use App\Http\Controllers\Controller;
use App\Http\Requests\Prospection\LoginRequest;
use App\Models\Prospection\ProspectionAccessToken;
use App\Models\Prospection\ProspectionUser;
use App\Services\Prospection\ProspectionSessionIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = ProspectionUser::where('email', $request->input('email'))->first();

        if (!$user || !Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Identifiants incorrects.'],
            ]);
        }

        if (!$user->active) {
            return response()->json([
                'data' => null,
                'errors' => [['code' => 'ACCOUNT_DISABLED', 'message' => 'Ce compte est désactivé.', 'field' => null]],
            ], 403);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'data' => ProspectionSessionIssuer::issue($user, $request->userAgent()),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ProspectionSessionIssuer::userPayload($request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->attributes->get('prospection_access_token');

        if ($token instanceof ProspectionAccessToken) {
            $token->delete();
        }

        return response()->json(['data' => ['logged_out' => true]]);
    }
}
