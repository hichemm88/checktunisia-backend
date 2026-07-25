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
use Illuminate\Validation\Rules\Password;

class AuthorityAdminController extends Controller {
    public function index(Request $request): JsonResponse {
        $users = User::role('authority_user')->with(['authorityProfile.organization'])
            ->paginate($request->integer('per_page', 50));
        return response()->json([
            'data' => collect($users->items())->map(fn($u) => ['id'=>$u->id,'first_name'=>$u->first_name,'last_name'=>$u->last_name,'email'=>$u->email,'status'=>$u->status,'organization'=>$u->authorityProfile?->organization?->name,'organization_id'=>$u->authorityProfile?->organization_id,'badge_number'=>$u->authorityProfile?->badge_number,'rank'=>$u->authorityProfile?->rank,'whatsapp_number'=>$u->authorityProfile?->whatsapp_number,'receives_whatsapp_fiches'=>(bool)$u->authorityProfile?->receives_whatsapp_fiches,'last_login_at'=>$u->last_login_at,'two_factor_confirmed_at'=>$u->two_factor_confirmed_at]),
            'meta' => ['total' => $users->total(), 'current_page' => $users->currentPage(), 'per_page' => $users->perPage()],
        ]);
    }
    /**
     * Normalise un numéro WhatsApp en chiffres internationaux (ex. +216 20 123 456
     * → 21620123456). Renvoie null si vide. Le JID …@c.us est dérivé à l'envoi.
     */
    private function normalizeWhatsapp(?string $raw): ?string {
        if ($raw === null) return null;
        $digits = preg_replace('/\D+/', '', $raw);
        return $digits === '' ? null : $digits;
    }

    public function store(Request $request): JsonResponse {
        $v = $request->validate(['first_name'=>['required','string','max:100'],'last_name'=>['required','string','max:100'],'email'=>['required','email','unique:users,email'],'password'=>['required', Password::min(12)->mixedCase()->numbers()->symbols()],'organization_id'=>['required','exists:authority_organizations,id'],'badge_number'=>['nullable','string','max:50'],'rank'=>['nullable','string','max:100'],
            // Numéro WhatsApp (international, 8–15 chiffres après nettoyage) et
            // interrupteur « reçoit les fiches ». Un agent ne peut pas recevoir
            // sans numéro.
            'whatsapp_number'=>['nullable','string','max:25'],
            'receives_whatsapp_fiches'=>['sometimes','boolean'],
            'expires_at'=>['nullable','date']]);
        $number = $this->normalizeWhatsapp($v['whatsapp_number'] ?? null);
        if ($number !== null && !preg_match('/^\d{8,15}$/', $number)) {
            return response()->json(['errors'=>[['code'=>'INVALID_WHATSAPP','message'=>'Numéro WhatsApp invalide (8 à 15 chiffres, format international).','field'=>'whatsapp_number']]], 422);
        }
        if (($v['receives_whatsapp_fiches'] ?? false) && $number === null) {
            return response()->json(['errors'=>[['code'=>'WHATSAPP_REQUIRED','message'=>'Un numéro WhatsApp est requis pour recevoir les fiches.','field'=>'whatsapp_number']]], 422);
        }
        $user = DB::transaction(function() use($v, $request, $number) {
            $u = User::create(['first_name'=>$v['first_name'],'last_name'=>$v['last_name'],'email'=>$v['email'],'password'=>Hash::make($v['password']),'status'=>'active','email_verified_at'=>now()]);
            $u->assignRole('authority_user');
            AuthorityUserProfile::create(['user_id'=>$u->id,'organization_id'=>$v['organization_id'],'badge_number'=>$v['badge_number']??null,'rank'=>$v['rank']??null,'whatsapp_number'=>$number,'receives_whatsapp_fiches'=>(bool)($v['receives_whatsapp_fiches']??false),'authorized_by'=>$request->user()->id,'authorized_at'=>now(),'expires_at'=>$v['expires_at']??null]);
            AuditLogger::log('authority_user.created', $u);
            return $u;
        });
        return response()->json(['data'=>['id'=>$user->id,'email'=>$user->email,'role'=>'authority_user']], 201);
    }
    public function update(Request $request, string $id): JsonResponse {
        $user = User::role('authority_user')->with('authorityProfile')->findOrFail($id);
        $v = $request->validate(['status'=>['sometimes','in:active,suspended,inactive'],'badge_number'=>['sometimes','nullable','string','max:50'],'rank'=>['sometimes','nullable','string','max:100'],'whatsapp_number'=>['sometimes','nullable','string','max:25'],'receives_whatsapp_fiches'=>['sometimes','boolean'],'expires_at'=>['nullable','date']]);
        if (isset($v['status'])) {
            $user->update(['status'=>$v['status']]);
            // A suspended/deactivated authority account (police/ministry — the
            // most sensitive credential in the system) must lose access
            // immediately rather than keep a valid session for up to 8h.
            if ($v['status'] !== 'active') $user->tokens()->delete();
        }
        $profile = $user->authorityProfile;
        if ($profile) {
            $updates = [];
            if (array_key_exists('badge_number', $v)) $updates['badge_number'] = $v['badge_number'];
            if (array_key_exists('rank', $v)) $updates['rank'] = $v['rank'];
            if (array_key_exists('expires_at', $v)) $updates['expires_at'] = $v['expires_at'];
            if (array_key_exists('whatsapp_number', $v)) {
                $number = $this->normalizeWhatsapp($v['whatsapp_number']);
                if ($number !== null && !preg_match('/^\d{8,15}$/', $number)) {
                    return response()->json(['errors'=>[['code'=>'INVALID_WHATSAPP','message'=>'Numéro WhatsApp invalide (8 à 15 chiffres, format international).','field'=>'whatsapp_number']]], 422);
                }
                $updates['whatsapp_number'] = $number;
            }
            if (array_key_exists('receives_whatsapp_fiches', $v)) $updates['receives_whatsapp_fiches'] = (bool)$v['receives_whatsapp_fiches'];
            // Cohérence : on ne peut pas « recevoir » sans numéro.
            $finalNumber = array_key_exists('whatsapp_number',$updates) ? $updates['whatsapp_number'] : $profile->whatsapp_number;
            $finalReceives = array_key_exists('receives_whatsapp_fiches',$updates) ? $updates['receives_whatsapp_fiches'] : $profile->receives_whatsapp_fiches;
            if ($finalReceives && !$finalNumber) {
                return response()->json(['errors'=>[['code'=>'WHATSAPP_REQUIRED','message'=>'Un numéro WhatsApp est requis pour recevoir les fiches.','field'=>'whatsapp_number']]], 422);
            }
            if ($updates) $profile->update($updates);
        }
        AuditLogger::log('authority_user.updated', $user);
        return response()->json(['data'=>['id'=>$user->id,'status'=>$user->status]]);
    }
    public function destroy(string $id): JsonResponse {
        $user = User::role('authority_user')->findOrFail($id);
        $user->update(['status'=>'inactive']);
        $user->tokens()->delete();
        $user->delete();
        AuditLogger::log('authority_user.deleted', $user);
        return response()->json(null, 204);
    }

    public function organizations(Request $request): JsonResponse {
        $query = AuthorityOrganization::withCount('userProfiles');
        if ($request->filled('search')) $query->where('name', 'ilike', "%{$request->search}%");
        if (!$request->boolean('include_inactive')) $query->where('is_active', true);
        $orgs = $query->orderBy('name')->paginate($request->integer('per_page', 50));
        return response()->json([
            'data' => $orgs->items(),
            'meta' => ['total' => $orgs->total(), 'current_page' => $orgs->currentPage(), 'per_page' => $orgs->perPage()],
        ]);
    }
    public function createOrganization(Request $request): JsonResponse {
        $v = $request->validate(['name'=>['required','string','max:255'],'type'=>['required','in:police,immigration,customs,judiciary,tax,ministry,other'],'code'=>['nullable','string','unique:authority_organizations,code'],'governorate'=>['nullable','string','max:100'],'description'=>['nullable','string']]);
        $org = AuthorityOrganization::create(array_merge($v, ['is_active' => true]));
        AuditLogger::log('authority_organization.created', $org);
        return response()->json(['data'=>$org], 201);
    }
    public function updateOrganization(Request $request, string $id): JsonResponse {
        $org = AuthorityOrganization::findOrFail($id);
        $v = $request->validate(['name'=>['sometimes','string','max:255'],'type'=>['sometimes','in:police,immigration,customs,judiciary,tax,ministry,other'],'governorate'=>['sometimes','nullable','string','max:100'],'description'=>['sometimes','nullable','string'],'is_active'=>['sometimes','boolean']]);
        $org->update($v);
        AuditLogger::log('authority_organization.updated', $org);
        return response()->json(['data'=>$org->fresh()]);
    }
    public function destroyOrganization(string $id): JsonResponse {
        $org = AuthorityOrganization::withCount('userProfiles')->findOrFail($id);
        if ($org->user_profiles_count > 0) {
            return response()->json([
                'errors' => [['code' => 'HAS_USERS', 'message' => "Cet organisme a encore {$org->user_profiles_count} utilisateur(s) rattaché(s) — réaffectez-les avant de supprimer."]],
            ], 422);
        }
        AuditLogger::log('authority_organization.deleted', $org);
        $org->delete();
        return response()->json(null, 204);
    }
}
