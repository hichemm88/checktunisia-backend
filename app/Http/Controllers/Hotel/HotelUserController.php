<?php
namespace App\Http\Controllers\Hotel;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class HotelUserController extends Controller {
    public function index(): JsonResponse {
        $hotel = app('tenant');
        $users = $hotel->users()->with('roles')->get();
        return response()->json(['data' => $users->map(fn($u) => ['id'=>$u->id,'first_name'=>$u->first_name,'last_name'=>$u->last_name,'email'=>$u->email,'role'=>$u->primary_role,'status'=>$u->status,'last_login_at'=>$u->last_login_at])]);
    }
    public function store(Request $request): JsonResponse {
        $hotel = app('tenant');
        $v = $request->validate(['first_name'=>['required','string','max:100'],'last_name'=>['required','string','max:100'],'email'=>['required','email','unique:users,email'],'role'=>['required','in:hotel_admin,receptionist'],'password'=>['required','string','min:8']]);
        $user = DB::transaction(function() use ($v, $hotel) {
            $u = User::create(['first_name'=>$v['first_name'],'last_name'=>$v['last_name'],'email'=>$v['email'],'password'=>Hash::make($v['password']),'status'=>'active','email_verified_at'=>now()]);
            $u->assignRole($v['role']);
            $hotel->users()->attach($u->id, ['granted_at'=>now()]);
            AuditLogger::log('user.created', $u, [], $u->only(['email','first_name','last_name']), hotelId: $hotel->id);
            return $u;
        });
        return response()->json(['data'=>['id'=>$user->id,'email'=>$user->email,'role'=>$user->primary_role]], 201);
    }
    public function update(Request $request, string $id): JsonResponse {
        $hotel = app('tenant');
        $user = $hotel->users()->findOrFail($id);
        $v = $request->validate(['role'=>['sometimes','in:hotel_admin,receptionist'],'status'=>['sometimes','in:active,inactive,suspended']]);
        if (isset($v['role'])) { $user->syncRoles([$v['role']]); }
        if (isset($v['status'])) { $user->update(['status' => $v['status']]); }
        AuditLogger::log('user.updated', $user, hotelId: $hotel->id);
        return response()->json(['data' => ['id'=>$user->id,'role'=>$user->primary_role,'status'=>$user->status]]);
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
