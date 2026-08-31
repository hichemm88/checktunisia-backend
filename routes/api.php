<?php

use App\Http\Controllers\Admin\AiCostController;
use App\Http\Controllers\Admin\AiPricingController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\HealthController;
use App\Http\Controllers\Admin\AuthorityAdminController;
use App\Http\Controllers\Admin\EmailTemplateAdminController;
use App\Http\Controllers\Admin\CouponController;
use App\Http\Controllers\Admin\HotelAdminController;
use App\Http\Controllers\Admin\KpiController;
use App\Http\Controllers\Admin\MediaAdminController;
use App\Http\Controllers\Admin\MenuItemAdminController;
use App\Http\Controllers\Admin\OrganizationAdminController;
use App\Http\Controllers\Admin\PageAdminController;
use App\Http\Controllers\Admin\PlanAdminController;
use App\Http\Controllers\Admin\PlatformSettingController;
use App\Http\Controllers\Admin\PlatformUserAdminController;
use App\Http\Controllers\Admin\SubscriptionAdminController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Authority\AuthorityDashboardController;
use App\Http\Controllers\Authority\AuthoritySearchController;
use App\Http\Controllers\Authority\ExportController;
use App\Http\Controllers\Authority\SecurityAlertController;
use App\Http\Controllers\Authority\WatchlistController;
use App\Http\Controllers\Hotel\ActivityLogController;
use App\Http\Controllers\Hotel\CheckInController;
use App\Http\Controllers\Hotel\DashboardController;
use App\Http\Controllers\Hotel\GuestController;
use App\Http\Controllers\Hotel\HotelProfileController;
use App\Http\Controllers\Hotel\HotelUserController;
use App\Http\Controllers\Hotel\MyPropertiesController;
use App\Http\Controllers\Hotel\OnboardingController;
use App\Http\Controllers\Hotel\OrganizationController;
use App\Http\Controllers\Hotel\PaymentController;
use App\Http\Controllers\Hotel\RoomController;
use App\Http\Controllers\Hotel\ScanController;
use App\Http\Controllers\Hotel\ScanEventController;
use App\Http\Controllers\Hotel\SubscriptionController;
use App\Http\Controllers\Hotel\WatchlistHitController;
use App\Http\Controllers\Internal\AiUsageIngestController;
use App\Http\Controllers\Payment\KonnectWebhookController;
use App\Http\Controllers\Notifications\DeviceController;
use App\Http\Controllers\Notifications\NotificationController;
use App\Http\Controllers\Public\PublicCmsController;
use App\Http\Controllers\Public\PublicPlatformController;
use App\Http\Controllers\Public\PublicRegistrationController;
use App\Http\Controllers\Referential\ReferentialController;
use App\Http\Controllers\Whatsapp\WhatsappAdminController;
use App\Http\Controllers\Whatsapp\WhatsappWebhookController;
use App\Http\Controllers\Whatsapp\WhatsappWorkerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->middleware('throttle:5,1')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('password/forgot', [AuthController::class, 'forgotPassword']);
    Route::post('password/reset', [AuthController::class, 'resetPassword']);
});

Route::get('referential/countries', [ReferentialController::class, 'countries']);
Route::get('referential/document-types', [ReferentialController::class, 'documentTypes']);
Route::get('subscriptions/plans', [ReferentialController::class, 'plans']);

// Self-service hotel registration (public)
Route::post('public/register', [PublicRegistrationController::class, 'register'])
    ->middleware('throttle:5,10');

// Public platform info (no auth)
Route::get('public/plans', [PublicPlatformController::class, 'plans']);
Route::get('public/settings', [PublicPlatformController::class, 'settings']);

// Public CMS (no auth) — pages publiées, menus, médias
Route::get('public/pages/{slug}', [PublicCmsController::class, 'page']);
Route::get('public/menus', [PublicCmsController::class, 'menus']);
Route::get('public/media/{id}', [PublicCmsController::class, 'media']);
Route::get('public/sitemap.xml', [PublicCmsController::class, 'sitemap']);

// Rappel serveur de Konnect. Sans session : c'est le prestataire qui prévient,
// pas un utilisateur. Le jeton est un segment de chemin et la vérité vient d'un
// appel sortant — voir KonnectWebhookController.
Route::match(['get', 'post'], 'payments/konnect/webhook/{token}', KonnectWebhookController::class)
    ->middleware('throttle:konnect-webhook');

/*
|--------------------------------------------------------------------------
| MODULE PROVISOIRE — Relais WhatsApp (à retirer après homologation MI)
| Voir PROMPT-CLAUDE-CODE-QAYED-AUTORITE.md
|--------------------------------------------------------------------------
*/

// État de santé (sans secret) — supervision + affichage admin.
Route::get('health/whatsapp', [WhatsappAdminController::class, 'health']);

// API interne consommée uniquement par le service Node (whatsapp-service/),
// authentifiée par secret partagé — pas de session utilisateur.
Route::prefix('internal/whatsapp')
    ->middleware(['whatsapp.worker', 'throttle:internal-worker'])
    ->group(function () {
        Route::get('next', [WhatsappWorkerController::class, 'next']);
        Route::get('control', [WhatsappWorkerController::class, 'control']);
        Route::get('scan/{scanId}', [WhatsappWorkerController::class, 'scan']);
        Route::post('jobs/{id}/result', [WhatsappWorkerController::class, 'result']);
        Route::post('session', [WhatsappWorkerController::class, 'session']);

        // Coffre de session : le worker dépose une copie chiffrée de son
        // appairage et la réclame au démarrage si son volume est vide. Sans
        // ceci, la session ne vit que sur un volume attaché à une instance —
        // sa perte impose un re-scan de QR, donc une coupure du canal légal.
        Route::get('session-archive', [WhatsappWorkerController::class, 'sessionArchive']);
        Route::get('session-archive/meta', [WhatsappWorkerController::class, 'sessionArchiveMeta']);
        Route::post('session-archive', [WhatsappWorkerController::class, 'storeSessionArchive']);
    });

/*
| Webhook de la WhatsApp Cloud API (Meta).
|
| Sans session utilisateur : c'est Meta qui appelle. Le GET répond au défi de
| vérification (jeton partagé), le POST est authentifié par la signature
| X-Hub-Signature-256 vérifiée dans le contrôleur — jamais par un middleware
| de session.
|
| URL à déclarer dans la console Meta : https://api.qayed.tn/api/v1/webhooks/whatsapp
*/
Route::get('webhooks/whatsapp', [WhatsappWebhookController::class, 'verify'])
    ->middleware('throttle:whatsapp-webhook');
Route::post('webhooks/whatsapp', [WhatsappWebhookController::class, 'handle'])
    ->middleware('throttle:whatsapp-webhook');

// Ingestion du tracking des coûts IA — consommée uniquement par la fonction
// serverless Vercel (scan CIN / repli passeport), authentifiée par secret partagé.
Route::post('internal/ai-usage', [AiUsageIngestController::class, 'store'])
    ->middleware('ai.tracking.secret');

/*
|--------------------------------------------------------------------------
| Authenticated Routes (all roles)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'audit'])->group(function () {

    // Seul logout reste joignable avec un token partiel : annuler une connexion
    // en cours doit toujours être possible sans avoir passé le TOTP.
    Route::post('auth/logout', [AuthController::class, 'logout']);

    // refresh et me exigent un token complet. refresh émettait un token ['*']
    // sans vérifier les capacités du token présenté : un token 2fa-pending
    // suffisait donc à contourner entièrement la 2FA. me exposait le profil
    // complet et la liste des permissions à ce même token partiel.
    Route::middleware('require.2fa')->group(function () {
        Route::post('auth/refresh', [AuthController::class, 'refresh']);
        Route::get('auth/me', [AuthController::class, 'me']);
    });

    // 2FA — verify accepts partial token; setup/disable require full token.
    // Throttled: a TOTP code is only 6 digits (1M possibilities) — without a
    // rate limit it's crackable well within its ~30s validity window.
    Route::post('auth/2fa/verify', [TwoFactorController::class, 'verify'])->middleware('throttle:5,1');
    Route::middleware('require.2fa')->group(function () {
        Route::get('auth/2fa/setup', [TwoFactorController::class, 'setup']);
        Route::post('auth/2fa/setup/confirm', [TwoFactorController::class, 'confirmSetup'])->middleware('throttle:5,1');
        Route::delete('auth/2fa/setup', [TwoFactorController::class, 'disable'])->middleware('throttle:5,1');

        // Ces deux routes VÉRIFIENT le mot de passe courant : sans limiteur
        // dédié, elles n'étaient couvertes que par le repli global à 120/min,
        // là où /auth/login est à 5/min. Une session volée permettait donc de
        // deviner le mot de passe à 172 000 essais/jour — et le deviner ouvre
        // le changement d'adresse e-mail, donc la prise de compte complète.
        Route::patch('profile', [AuthController::class, 'updateProfile'])
            ->middleware('throttle:credential-check');
        Route::post('profile/password', [AuthController::class, 'changePassword'])
            ->middleware('throttle:credential-check');
    });

    /*
    |----------------------------------------------------------------------
    | Push notifications (mobile app)
    |
    | Device tokens: any authenticated user may register their device.
    | Notification centre: both roles read their OWN rows (managers get check-in
    | activity, receptionists get manager messages). Broadcast is manager-only.
    | (org-level, no tenant needed).
    |----------------------------------------------------------------------
    */
    Route::post('devices', [DeviceController::class, 'store']);
    Route::delete('devices/{token}', [DeviceController::class, 'destroy'])->where('token', '.*');

    Route::middleware('role:hotel_admin|receptionist')->group(function () {
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
        Route::post('notifications/{id}/read', [NotificationController::class, 'markRead']);
    });

    // Manager → receptionists broadcast.
    Route::middleware('role:hotel_admin')->group(function () {
        Route::get('notifications/recipients', [NotificationController::class, 'recipients']);
        Route::post('notifications/broadcast', [NotificationController::class, 'broadcast']);
    });

    /*
    |----------------------------------------------------------------------
    | Hotel Staff Routes — Group A: require a resolved tenant (property)
    |
    | These routes need app('tenant') to be bound. Only reachable once the
    | org has at least one property.
    |----------------------------------------------------------------------
    */
    Route::prefix('hotel')
        ->middleware(['role:hotel_admin|receptionist', 'tenant'])
        ->group(function () {

            // Dashboard
            Route::get('dashboard', [DashboardController::class, 'index']);
            // Occupation navigable (semaine par semaine vers le passé)
            Route::get('dashboard/occupancy', [DashboardController::class, 'occupancy']);

            // Properties this account is attached to (switcher) — for both roles
            Route::get('my-properties', [MyPropertiesController::class, 'index']);

            // Rooms (read for all staff)
            Route::get('rooms', [RoomController::class, 'index']);
            Route::get('rooms/availability', [RoomController::class, 'availability']);

            // Watchlist hits
            Route::get('watchlist-hits', [WatchlistHitController::class, 'index']);
            Route::post('watchlist-hits/{id}/acknowledge', [WatchlistHitController::class, 'acknowledge']);

            // Check-ins (read for all staff)
            Route::get('check-ins', [CheckInController::class, 'index']);
            Route::get('check-ins/{id}', [CheckInController::class, 'show']);

            // OCR scan status
            Route::get('scans/{scan_id}/status', [ScanController::class, 'status']);

            // Telemetrie OCR MRZ local (beacon metadata-only, pour le graphe comparatif).
            Route::post('scan-events/mrz-local', [ScanEventController::class, 'mrzLocal']);

            // ── Subscription-gated: write operations ──────────────────────
            Route::middleware('subscription.active')->group(function () {
                Route::post('check-ins', [CheckInController::class, 'store']);
                Route::patch('check-ins/{id}', [CheckInController::class, 'update']);
                Route::post('check-ins/{id}/complete', [CheckInController::class, 'complete']);
                Route::post('check-ins/{id}/checkout', [CheckInController::class, 'checkout']);
                Route::post('check-ins/{id}/cancel', [CheckInController::class, 'cancel']);
                Route::post('check-ins/{check_in_id}/guests', [GuestController::class, 'store']);
                Route::patch('check-ins/{check_in_id}/guests/{guest_id}', [GuestController::class, 'update']);
                Route::delete('check-ins/{check_in_id}/guests/{guest_id}', [GuestController::class, 'destroy']);
                // 10 Mo par requête + un appel au modèle de vision derrière :
                // limité plus strictement que le reste du périmètre hôtel.
                Route::post('check-ins/{check_in_id}/scans', [ScanController::class, 'store'])
                    ->middleware('throttle:scan-upload');
            });

            // Hotel profile (read) — both roles need this to print fiches de police
            Route::get('profile', [HotelProfileController::class, 'show']);

            // ── Hotel admin only (tenant-aware) ────────────────────────────
            Route::middleware('role:hotel_admin')->group(function () {

                // Envoi direct des fiches : voir/cocher les agents destinataires (Phase 2).
                Route::get('whatsapp-recipients', [\App\Http\Controllers\Hotel\HotelWhatsappRecipientController::class, 'index']);
                Route::put('whatsapp-recipients', [\App\Http\Controllers\Hotel\HotelWhatsappRecipientController::class, 'sync']);

                // Export des fiches de police (PDF par email) sur une plage de dates.
                Route::post('exports/police-fiches', [\App\Http\Controllers\Hotel\HotelExportController::class, 'policeFiches']);

                // ── Owner only (matrice role_org) : modification établissement
                //    et gestion des utilisateurs ─────────────────────────────
                Route::middleware('org.owner')->group(function () {
                    // Hotel profile (write)
                    Route::patch('profile', [HotelProfileController::class, 'update']);

                    // Staff management
                    Route::get('users', [HotelUserController::class, 'index']);
                    Route::post('users', [HotelUserController::class, 'store']);
                    Route::patch('users/{id}', [HotelUserController::class, 'update']);
                    Route::delete('users/{id}', [HotelUserController::class, 'destroy']);
                    Route::post('users/{id}/resend-invite', [HotelUserController::class, 'resendInvite']);
                });

                // Staff activity feed
                Route::get('activity', [ActivityLogController::class, 'index']);

                // Check-in deletion — admin only, any status (soft delete)
                Route::delete('check-ins/{id}', [CheckInController::class, 'destroy']);

                // Manager établissement : annuler un départ enregistré par erreur
                // (Terminé → Actif). Correction réservée au manager.
                Route::post('check-ins/{id}/revert-checkout', [CheckInController::class, 'revertCheckout']);

                // Room CRUD (write)
                Route::post('rooms', [RoomController::class, 'store']);
                Route::patch('rooms/{id}', [RoomController::class, 'update']);
                Route::delete('rooms/{id}', [RoomController::class, 'destroy']);
            });
        });

    /*
    |----------------------------------------------------------------------
    | Hotel Staff Routes — Group B: org-level, NO tenant required
    |
    | Reachable before any property exists (new registration flow).
    | Subscription is org-level; onboarding and org management don't
    | need a resolved property.
    |----------------------------------------------------------------------
    */

    // Subscription read — available to all hotel staff (org-level)
    Route::prefix('hotel')
        ->middleware(['role:hotel_admin|receptionist'])
        ->group(function () {
            Route::get('subscription', [SubscriptionController::class, 'current']);
        });

    // Lecture des offres et simulation d'un changement : sans effet de bord,
    // donc ouvert au même niveau que la lecture de l'abonnement.
    Route::prefix('hotel')
        ->middleware(['role:hotel_admin|receptionist'])
        ->group(function () {
            Route::get('subscription/plans', [SubscriptionController::class, 'plans']);
            Route::get('subscription/history', [SubscriptionController::class, 'history']);
        });

    // Gestion de l'abonnement en self-service — écritures réservées au
    // gestionnaire de l'organisation (mêmes garde-fous que la facturation :
    // hotel_admin + propriétaire de l'organisation). Un réceptionniste voit
    // son abonnement, il ne le modifie pas.
    Route::prefix('hotel')
        ->middleware(['role:hotel_admin', 'org.owner', 'throttle:10,10'])
        ->group(function () {
            Route::post('subscription/preview-change', [SubscriptionController::class, 'previewChange']);
            Route::post('subscription/change', [SubscriptionController::class, 'changePlan']);
            Route::post('subscription/change/cancel', [SubscriptionController::class, 'cancelChange']);
            Route::post('subscription/cancel', [SubscriptionController::class, 'cancelSubscription']);
            Route::post('subscription/reactivate', [SubscriptionController::class, 'reactivateSubscription']);
            // Déprécié — conservé pour les clients mobiles déjà déployés.
            Route::post('subscription/upgrade-request', [SubscriptionController::class, 'requestUpgrade']);
        });

    // Invoice history + payment (Flouci/virement) — hotel_admin only, matching
    // the billing-tab access level elsewhere. Org-scoped, not tenant-scoped:
    // admin-created invoices are org-level and must be reachable before any
    // property exists.
    Route::prefix('hotel')
        ->middleware(['role:hotel_admin', 'org.owner'])
        ->group(function () {
            Route::get('invoices', [SubscriptionController::class, 'invoices']);
            Route::get('invoices/{id}/pdf', [SubscriptionController::class, 'downloadInvoicePdf']);
            // Opérations d'argent : limitées bas. Le contrôle de doublon de
            // paiement n'étant pas verrouillé en base (SEC-12), réduire le
            // rythme réduit mécaniquement la fenêtre de course.
            Route::middleware('throttle:payments')->group(function () {
                Route::post('payments/initiate', [PaymentController::class, 'initiate']);
                Route::get('payments/{id}/verify', [PaymentController::class, 'verify']);
                Route::post('payments/declare-virement', [PaymentController::class, 'declareVirement']);
            });
        });

    // Onboarding + org management — hotel_admin only, no tenant needed
    Route::prefix('hotel')
        ->middleware(['role:hotel_admin'])
        ->group(function () {

            // Onboarding status : lisible par tout hotel_admin — le gate de
            // redirection post-login en a besoin, y compris pour les « admin »
            // (écran Configuration en attente).
            Route::get('onboarding/status', [OnboardingController::class, 'status']);

            // Organization info & properties (lecture)
            Route::get('organization', [OrganizationController::class, 'show']);
            Route::get('organization/properties', [OrganizationController::class, 'properties']);
            Route::get('organization/properties/{id}/rooms', [OrganizationController::class, 'propertyRooms']);

            // Rooms (écriture) — gestion opérationnelle, ouverte aux hotel_admin
            Route::post('organization/properties/{id}/rooms', [OrganizationController::class, 'addPropertyRoom']);
            Route::post('organization/properties/{id}/rooms/bulk', [OrganizationController::class, 'bulkAddPropertyRooms']);
            Route::patch('organization/properties/{id}/rooms/{roomId}', [OrganizationController::class, 'updatePropertyRoom']);
            Route::delete('organization/properties/{id}/rooms/{roomId}', [OrganizationController::class, 'deletePropertyRoom']);

            // ── Owner only (matrice role_org) : onboarding, création /
            //    modification / suppression d'établissement, transfert ─────────
            Route::middleware('org.owner')->group(function () {
                Route::post('onboarding/complete', [OnboardingController::class, 'complete']);
                Route::patch('organization', [OrganizationController::class, 'update']);
                Route::post('organization/properties', [OrganizationController::class, 'addProperty']);
                Route::patch('organization/properties/{id}', [OrganizationController::class, 'updateProperty']);
                Route::delete('organization/properties/{id}', [OrganizationController::class, 'deleteProperty']);
                Route::post('organization/transfer-ownership', [OrganizationController::class, 'transferOwnership']);
            });
        });

    /*
    |----------------------------------------------------------------------
    | Authority Routes (read-only)
    |----------------------------------------------------------------------
    */
    Route::prefix('authority')
        ->middleware(['role:authority_user', 'require.2fa', 'authority.credential', 'throttle:60,1'])
        ->group(function () {
            Route::get('dashboard', [AuthorityDashboardController::class, 'dashboard']);
            Route::get('alerts', [AuthorityDashboardController::class, 'alerts']);
            Route::get('activity', [AuthorityDashboardController::class, 'activity']);
            Route::get('search', [AuthoritySearchController::class, 'search']);
            Route::get('recent-check-ins', [AuthoritySearchController::class, 'recentCheckIns']);
            Route::get('guests/{id}', [AuthoritySearchController::class, 'show']);
            Route::get('hotels', [AuthoritySearchController::class, 'hotels']);
            Route::get('hotels/{id}', [AuthoritySearchController::class, 'showHotel']);
            Route::get('hotels/{id}/check-ins', [AuthoritySearchController::class, 'hotelCheckIns']);
            Route::get('watchlist', [WatchlistController::class, 'index']);
            Route::post('watchlist', [WatchlistController::class, 'store']);
            Route::patch('watchlist/{id}', [WatchlistController::class, 'update']);
            Route::delete('watchlist/{id}', [WatchlistController::class, 'destroy']);
            Route::post('watchlist/import', [WatchlistController::class, 'import']);
            Route::get('watchlist/template', [WatchlistController::class, 'template']);
            Route::get('security-alerts', [SecurityAlertController::class, 'index']);
            Route::post('security-alerts/{id}/seen', [SecurityAlertController::class, 'seen']);
            Route::post('security-alerts/{id}/acknowledge', [SecurityAlertController::class, 'acknowledge']);
            Route::get('guests/{id}/export/pdf', [ExportController::class, 'guestPdf']);
            Route::get('export/stays', [ExportController::class, 'staysCsv']);
        });

    /*
    |----------------------------------------------------------------------
    | Platform Admin Routes
    |----------------------------------------------------------------------
    */
    // admin.2fa : le compte platform_admin n'a aucun scoping tenant — la 2FA y
    // est obligatoire, comme pour les comptes autorité. Un admin sans TOTP
    // configurée reçoit 403 2FA_SETUP_REQUIRED et est redirigé vers la page de
    // configuration (joignable, elle, avec un token complet sans 2FA).
    Route::prefix('admin')
        ->middleware(['role:platform_admin', 'admin.2fa', 'throttle:60,1'])
        ->group(function () {

            Route::get('dashboard', [HotelAdminController::class, 'dashboard']);
            Route::get('search', [HotelAdminController::class, 'search']);

            // KPIs business (MRR, ARPU, churn, activation, conversion d'essai)
            Route::get('metrics/kpis', [KpiController::class, 'index']);

            // Couts IA (Claude vision : scan CIN + repli passeport)
            Route::get('ai-costs/summary', [AiCostController::class, 'summary']);
            Route::get('ai-costs/by-establishment', [AiCostController::class, 'byEstablishment']);
            Route::get('ai-costs/daily', [AiCostController::class, 'daily']);
            Route::get('ai-costs/scan-comparison', [AiCostController::class, 'scanComparison']);
            Route::get('ai-pricing', [AiPricingController::class, 'index']);
            Route::put('ai-pricing/{id}', [AiPricingController::class, 'update']);

            // Hébergeurs (Organization — société/particulier)
            Route::get('hosts', [OrganizationAdminController::class, 'index']);
            Route::post('hosts', [OrganizationAdminController::class, 'store']);
            Route::get('hosts/{id}', [OrganizationAdminController::class, 'show']);
            Route::patch('hosts/{id}', [OrganizationAdminController::class, 'update']);
            Route::delete('hosts/{id}', [OrganizationAdminController::class, 'destroy']);
            Route::post('hosts/{id}/suspend', [OrganizationAdminController::class, 'suspend']);
            Route::post('hosts/{id}/activate', [OrganizationAdminController::class, 'activate']);

            // Hotels (établissements)
            Route::get('hotels', [HotelAdminController::class, 'index']);
            Route::post('hotels', [HotelAdminController::class, 'store']);
            Route::get('hotels/{id}', [HotelAdminController::class, 'show']);
            Route::patch('hotels/{id}', [HotelAdminController::class, 'update']);
            Route::delete('hotels/{id}', [HotelAdminController::class, 'destroy']);
            Route::post('hotels/{id}/suspend', [HotelAdminController::class, 'suspend']);
            Route::post('hotels/{id}/activate', [HotelAdminController::class, 'activate']);

            // Hotel users (scoped, used by the hotel detail panel)
            Route::get('hotels/{hotel_id}/users', [HotelAdminController::class, 'getUsers']);
            Route::post('hotels/{hotel_id}/users', [HotelAdminController::class, 'createUser']);

            // Utilisateurs — vue globale (tous hébergeurs/établissements confondus)
            Route::get('users', [PlatformUserAdminController::class, 'index']);
            Route::post('users', [PlatformUserAdminController::class, 'store']);
            Route::patch('users/{id}', [PlatformUserAdminController::class, 'update']);
            Route::delete('users/{id}', [PlatformUserAdminController::class, 'destroy']);
            Route::post('users/{id}/resend-invite', [PlatformUserAdminController::class, 'resendInvite']);

            // Subscriptions & invoices — hébergeur-scoped (subscriptions/invoices are org-level;
            // no hotel-scoped equivalent exists anymore — every hotel now requires an organization).
            Route::get('hosts/{host_id}/subscriptions', [SubscriptionAdminController::class, 'indexForHost']);
            Route::post('hosts/{host_id}/subscriptions', [SubscriptionAdminController::class, 'storeForHost']);
            Route::patch('hosts/{host_id}/subscriptions/{id}', [SubscriptionAdminController::class, 'updateForHost']);
            // Grille V2 : migration manuelle d'un compte legacy (action explicite, jamais automatique).
            Route::post('hosts/{host_id}/subscriptions/{id}/migrate-to-v2', [SubscriptionAdminController::class, 'migrateToV2']);
            Route::get('hosts/{host_id}/invoices', [SubscriptionAdminController::class, 'invoicesForHost']);
            Route::post('hosts/{host_id}/invoices', [SubscriptionAdminController::class, 'createInvoiceForHost']);
            Route::patch('hosts/{host_id}/invoices/{id}', [SubscriptionAdminController::class, 'updateInvoiceForHost']);
            Route::delete('hosts/{host_id}/invoices/{id}', [SubscriptionAdminController::class, 'destroyInvoiceForHost']);
            Route::get('hosts/{host_id}/invoices/{id}/pdf', [SubscriptionAdminController::class, 'downloadInvoicePdf']);
            Route::get('invoices', [SubscriptionAdminController::class, 'allInvoices']);

            // Manual bank-transfer (virement) validation — hébergeur declares via
            // POST /hotel/payments/declare-virement, admin confirms or rejects here.
            Route::post('payments/{payment_id}/validate-virement', [SubscriptionAdminController::class, 'validateVirement']);
            Route::post('payments/{payment_id}/reject-virement', [SubscriptionAdminController::class, 'rejectVirement']);

            // Authority users
            Route::get('authority-users', [AuthorityAdminController::class, 'index']);
            Route::post('authority-users', [AuthorityAdminController::class, 'store']);
            Route::patch('authority-users/{id}', [AuthorityAdminController::class, 'update']);
            Route::post('authority-users/{id}/invite', [AuthorityAdminController::class, 'invite']);
            Route::delete('authority-users/{id}', [AuthorityAdminController::class, 'destroy']);

            // Authority organizations (police / immigration / ministère...)
            Route::get('authority-organizations', [AuthorityAdminController::class, 'organizations']);
            Route::post('authority-organizations', [AuthorityAdminController::class, 'createOrganization']);
            Route::patch('authority-organizations/{id}', [AuthorityAdminController::class, 'updateOrganization']);
            Route::delete('authority-organizations/{id}', [AuthorityAdminController::class, 'destroyOrganization']);

            // Audit logs
            // Santé d'exploitation : profondeur de file, jobs échoués,
            // battement du planificateur, outbox WhatsApp. Compteurs seulement,
            // aucune donnée personnelle. /up ne dit que « le processus vit ».
            Route::get('health', [HealthController::class, 'index']);

            // Dead-letter : jobs définitivement abandonnés + rejeu.
            Route::get('health/failed-jobs', [HealthController::class, 'failedJobs']);
            Route::post('health/failed-jobs/{uuid}/retry', [HealthController::class, 'retryFailedJob']);

            Route::get('audit-logs', [AuditLogController::class, 'index']);
            Route::get('audit-logs/actions', [AuditLogController::class, 'actions']);
            Route::get('audit-logs/export', [AuditLogController::class, 'export']);
            Route::get('audit-logs/{id}', [AuditLogController::class, 'show']);
            Route::get('authority-search-logs', [AuditLogController::class, 'searchLogs']);

            // Platform settings (payment methods, Flouci config, RIB)
            Route::get('platform-settings', [PlatformSettingController::class, 'show']);
            Route::patch('platform-settings', [PlatformSettingController::class, 'update']);

            // Payments (read-only ledger)
            Route::get('payments', [PlatformSettingController::class, 'payments']);

            // Grille V2 — pilotage des quotas de check-ins (outil d'upsell) :
            // comptes à ≥80 % / en dépassement ce mois-ci, dépassements
            // clôturés + export CSV.
            Route::get('quotas', [\App\Http\Controllers\Admin\QuotaAdminController::class, 'index']);
            Route::get('quotas/overages', [\App\Http\Controllers\Admin\QuotaAdminController::class, 'overages']);
            Route::get('quotas/export', [\App\Http\Controllers\Admin\QuotaAdminController::class, 'export']);

            // Codes promo (remise sur facture)
            Route::get('coupons', [CouponController::class, 'index']);
            Route::post('coupons', [CouponController::class, 'store']);
            Route::patch('coupons/{id}', [CouponController::class, 'update']);
            Route::delete('coupons/{id}', [CouponController::class, 'destroy']);

            // Subscription plans management (pricing + trilingual marketing content)
            Route::get('plans', [PlanAdminController::class, 'index']);
            Route::post('plans', [PlanAdminController::class, 'store']);
            Route::patch('plans/{id}', [PlanAdminController::class, 'update']);
            Route::delete('plans/{id}', [PlanAdminController::class, 'destroy']);

            // CMS : pages dynamiques (Puck), menus publics, médias
            Route::get('pages', [PageAdminController::class, 'index']);
            Route::post('pages', [PageAdminController::class, 'store']);
            Route::get('pages/{id}', [PageAdminController::class, 'show']);
            Route::patch('pages/{id}', [PageAdminController::class, 'update']);
            Route::delete('pages/{id}', [PageAdminController::class, 'destroy']);
            Route::get('menu-items', [MenuItemAdminController::class, 'index']);
            Route::post('menu-items', [MenuItemAdminController::class, 'store']);
            Route::patch('menu-items/{id}', [MenuItemAdminController::class, 'update']);
            Route::delete('menu-items/{id}', [MenuItemAdminController::class, 'destroy']);
            Route::get('media', [MediaAdminController::class, 'index']);
            Route::post('media', [MediaAdminController::class, 'store']);
            Route::delete('media/{id}', [MediaAdminController::class, 'destroy']);

            // MODULE PROVISOIRE — Relais WhatsApp (à retirer après homologation MI).
            // Voir PROMPT-CLAUDE-CODE-QAYED-AUTORITE.md
            Route::get('whatsapp/health', [WhatsappAdminController::class, 'health']);
            Route::get('whatsapp/logs', [WhatsappAdminController::class, 'logs']);
            Route::post('whatsapp/logs/resend-all', [WhatsappAdminController::class, 'resendAll']);
            Route::post('whatsapp/logs/{id}/resend', [WhatsappAdminController::class, 'resend']);
            Route::post('whatsapp/test', [WhatsappAdminController::class, 'test']);
            Route::post('whatsapp/pause', [WhatsappAdminController::class, 'pause']);
            Route::post('whatsapp/resume', [WhatsappAdminController::class, 'resume']);

            // Email templates
            Route::get('emails', [EmailTemplateAdminController::class, 'index']);
            Route::patch('emails/{key}', [EmailTemplateAdminController::class, 'update']);
            Route::get('emails/{key}/preview', [EmailTemplateAdminController::class, 'preview']);
            Route::post('emails/{key}/send-test', [EmailTemplateAdminController::class, 'sendTest']);
            Route::post('emails/send-reminders', [EmailTemplateAdminController::class, 'sendReminders']);
        });
});
