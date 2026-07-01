<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\AuthorityOrganization;
use App\Models\AuthorityUserProfile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthorityAdminController extends Controller {
    public function index(): JsonResponse {
        $users = User::role('authority_user')->with(['authorityProfile.organization'])->get();
        return response()->json(['data' => $users->map(fn($u) => ['id'=>$u->id,'first_name'=>$u->first_name,'last_name'=>$u->last_name,'email'=>$u->email,'status'=>$u->status,'organization'=>$u->authorityProfile?->organization?->name,'badge_number'=>$u->authorityProfile?->badge_number,'last_login_at'=>$u->last_login_at])]);
    }
    public function store(Request $request): JsonResponse {
        $v = $request->validate(['first_name'=>['required','string','max:100'],'last_name'=>['required','string','max:100'],'email'=>['required','email','unique:users,email'],'password'=>['required','string','min:8'],'organization_id'=>['required','exists:authority_organizations,id'],'badge_number'=>['nullable','string','max:50'],'rank'=>['nullable','string','max:100'],'expires_at'=>['nullable','date']]);
        $user = DB::transaction(function() use($v, $request) {
            $u = User::create(['first_name'=>$v['first_name'],'last_name'=>$v['last_name'],'email'=>$v['email'],'password'=>Hash::make($v['password']),'status'=>'active','email_verified_at'=>now()]);
            $u->assignRole('authority_user');
            AuthorityUserProfile::create(['user_id'=>$u->id,'organization_id'=>$v['organization_id'],'badge_number'=>$v['badge_number']??null,'rank'=>$v['rank']??null,'authorized_by'=>$request->user()->id,'authorized_at'=>now(),'expires_at'=>$v['expires_at']??null]);
            AuditLogger::log('authority_user.created', $u);
            return $u;
        });
        return response()->json(['data'=>['id'=>$user->id,'email'=>$user->email,'role'=>'authority_user']], 201);
    }
    public function update(Request $request, string $id): JsonResponse {
        $user = User::role('authority_user')->findOrFail($id);
        $v = $request->validate(['status'=>['sometimes','in:active,suspended,inactive'],'expires_at'=>['nullable','date']]);
        if (isset($v['status'])) $user->update(['status'=>$v['status']]);
        if (isset($v['expires_at'])) $user->authorityProfile?->update(['expires_at'=>$v['expires_at']]);
        AuditLogger::log('authority_user.updated', $user);
        return response()->json(['data'=>['id'=>$user->id,'status'=>$user->status]]);
    }
    public function organizations(): JsonResponse {
        return response()->json(['data' => AuthorityOrganization::where('is_active',true)->get()]);
    }
    public function createOrganization(Request $request): JsonResponse {
        $v = $request->validate(['name'=>['required','string','max:255'],'type'=>['required','in:police,immigration,customs,judiciary,tax,other'],'code'=>['nullable','string','unique:authority_organizations,code'],'description'=>['nullable','string']]);
        $org = AuthorityOrganization::create($v);
        return response()->json(['data'=>$org], 201);
    }
}
