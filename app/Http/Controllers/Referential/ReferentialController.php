<?php
namespace App\Http\Controllers\Referential;
use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\DocumentType;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferentialController extends Controller {
    public function countries(Request $request): JsonResponse {
        $query = Country::where('is_active', true)->orderBy('sort_order')->orderBy('name_fr');
        if ($request->filled('search')) $query->where(fn($q) => $q->where('name_fr','ilike',"%{$request->search}%")->orWhere('alpha3','ilike',"%{$request->search}%"));
        return response()->json(['data' => $query->get(['code','alpha3','name_en','name_fr','name_ar','flag_emoji'])]);
    }
    public function documentTypes(): JsonResponse {
        return response()->json(['data' => DocumentType::where('is_active',true)->get(['code','name_en','name_fr','mrz_format'])]);
    }
    public function plans(): JsonResponse {
        // Seuls les plans souscriptibles (grille publique) — les plans legacy
        // (is_public=false) restent servis aux abonnés existants via /hotel/subscription.
        return response()->json(['data' => SubscriptionPlan::where('is_active',true)->where('is_public',true)->orderBy('sort_order')->get()]);
    }
}
