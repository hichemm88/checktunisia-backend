<?php
namespace App\Http\Controllers\Hotel;
use App\Http\Controllers\Controller;
use App\Mail\WelcomeUserMail;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class HotelUserController extends Controller {
    public function index(): JsonResponse {
        $hotel = app('tenant');
        $users = $hotel->users()->with('roles')->get();
        return response()->json(['data' => $users->map(fn($u) => ['id'=>$u->id,'first_name'=>$u->first_name,'last_name'=>$u->last_name,'email'=>$u->email,'role'=>$u->primary_role,'status'=>$u->status,'last_login_at'=>$u->last_login_at])]);
    }

    public function store(Request $request): JsonResponse {
        $hotel = app('tenant');
        $v = $request->validate([
            'first_name' => ['required','string','max:100'],
            'last_name'  => ['required','string','max:100'],
            'email'      => ['required','email','unique:users,email'],
            'role'       => ['required','in:hotel_admin,receptionist'],
        ]);

        $tempPassword = Str::random(12);

        $user = DB::transaction(function() use ($v, $hotel, $tempPassword) {
            $u = User::create([
                'first_name'        => $v['first_name'],
                'last_name'         => $v['last_name'],
                'email'             => $v['email'],
                'password'          => Hash::make($tempPassword),
                'status'            => 'active',
                'email_verified_at' => now(),
            ]);
            $u->assignRole($v['role']);
            $hotel->users()->attach($u->id, ['granted_at' => now()]);
            AuditLogger::log('user.created', $u, [], $u->only(['email','first_name','last_name']), hotelId: $hotel->id);
            return $u;
        });

        // Send welcome email with temporary password (outside transaction)
        try {
            Mail::to($user->email)->send(new WelcomeUserMail(
                firstName:         $user->first_name,
                lastName:          $user->last_name,
                email:             $user->email,
                temporaryPassword: $tempPassword,
                hotelName:         $hotel->name,
                role:              $v['role'],
            ));
        } catch (\Throwable $e) {
            // Log but don't fail — user is created, email is best-effort
            \Log::warning('Welcome email failed for user '.$user->id.': '.$e->getMessage());
        }

        return response()->json(['data' => [
            'id'    => $user->id,
            'email' => $user->email,
            'role'  => $user->primary_role,
        ]], 201);
    }

    public function update(Request $request, string $id): JsonResponse {
        $hotel = app('tenant');
        $user = $hotel->users()->findOrFail($id);
        $v = $request->validate([
            'first_name' => ['sometimes','string','max:100'],
            'last_name'  => ['sometimes','string','max:100'],
            'role'       => ['sometimes','in:hotel_admin,receptionist'],
            'status'     => ['sometimes','in:active,inactive,suspended'],
        ]);
        $fields = array_filter([
            'first_name' => $v['first_name'] ?? null,
            'last_name'  => $v['last_name']  ?? null,
            'status'     => $v['status']     ?? null,
        ], fn($val) => $val !== null);
        if ($fields) { $user->update($fields); }
        if (isset($v['role'])) { $user->syncRoles([$v['role']]); }
        AuditLogger::log('user.updated', $user, hotelId: $hotel->id);
        return response()->json(['data' => [
            'id'         => $user->id,
            'first_name' => $user->first_name,
            'last_name'  => $user->last_name,
            'role'       => $user->primary_role,
            'status'     => $user->status,
        ]]);
    }

    public function destroy(string $id): JsonResponse {
        $hotel = app('tenant');
        $user = $hotel->users()->findOrFail($id);
        $user->update(['status'=>'inactive']);
        $user->delete();
        AuditLogger::log('user.deleted', $user, hotelId: $hotel->id);
        return response()->json(null, 204);
    }
}
