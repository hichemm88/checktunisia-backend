<?php

namespace App\Http\Controllers\Prospection;

use App\Http\Controllers\Controller;
use App\Http\Requests\Prospection\StoreUserRequest;
use App\Models\Prospection\ProspectionUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Gestion des comptes membres du CRM — réservée à l'admin (§ Authentification :
 * "Prévois la création d'autres comptes membres depuis le compte admin").
 * Pas d'inscription publique : ces routes sont la SEULE façon de créer un
 * compte, en plus des 2 comptes seedés au déploiement.
 */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json([
            'data' => ProspectionUser::orderBy('name')->get()->map(fn (ProspectionUser $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'active' => $u->active,
                'last_login_at' => $u->last_login_at,
            ]),
        ]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $user = ProspectionUser::create([
            'name' => $request->input('name'),
            'email' => $request->input('email'),
            'password' => Hash::make($request->input('password')),
            'role' => $request->input('role'),
        ]);

        return response()->json(['data' => ['id' => $user->id]], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $user = ProspectionUser::findOrFail($id);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'role' => ['sometimes', 'in:admin,membre'],
            'active' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'string', 'min:8'],
        ]);

        if (array_key_exists('password', $data)) {
            $data['password'] = Hash::make($data['password']);
        }

        $user->update($data);

        return response()->json(['data' => ['id' => $user->id]]);
    }

    private function authorizeAdmin(Request $request): void
    {
        if (! $request->user()?->isAdmin()) {
            abort(response()->json([
                'data' => null,
                'errors' => [['code' => 'PERMISSION_DENIED', 'message' => 'Réservé aux administrateurs.', 'field' => null]],
            ], 403));
        }
    }
}
