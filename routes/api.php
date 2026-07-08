<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Admin\HotelAdminController;
use App\Http\Controllers\Admin\SubscriptionAdminController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\AuthorityAdminController;
use App\Http\Controllers\Admin\OrganizationAdminController;
use App\Http\Controllers\Admin\PlatformUserAdminController;
use App\Http\Controllers\Admin\EmailTemplateAdminController;
use App\Http\Controllers\Hotel\CheckInController;
use App\Http\Controllers\Hotel\GuestController;
use App\Http\Controllers\Hotel\ScanController;
use App\Http\Controllers\Hotel\RoomController;
use App\Http\Controllers\Hotel\DashboardController;
use App\Http\Controllers\Hotel\HotelProfileController;
use App\Http\Controllers\Hotel\ActivityLogController;
use App\Http\Controllers\Hotel\HotelUserController;
use App\Http\Controllers\Hotel\MyPropertiesController;
use App\Http\Controllers\Hotel\OnboardingController;
use App\Http\Controllers\Hotel\OrganizationController;
use App\Http\Controllers\Authority\ExportController;
use App\Http\Controllers\Public\PublicRegistrationController;
use App\Http\Controllers\Public\PublicPlatformController;
use App\Http\Controllers\Admin\PlatformSettingController;
use App\Http\Controllers\Admin\PlanAdminController;
use App\Http\Controllers\Hotel\SubscriptionController;
use App\Http\Controllers\Authority\AuthoritySearchController;
use App\Http\Controllers\Authority\AuthorityDashboardController;
use App\Http\Controllers\Authority\WatchlistController;
use App\Http\Controllers\Hotel\WatchlistHitController;
use App\Http\Controllers\Hotel\PaymentController;
use App\Http\Controllers\Referential\ReferentialController;
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
Route::get('public/plans',    [PublicPlatformController::class, 'plans']);
Route::get('public/settings', [PublicPlatformController::class, 'settings']);

/*
|--------------------------------------------------------------------------
| Authenticated Routes (all roles)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'audit'])->group(function () {

    // Auth (no require.2fa here — these must be reachable with partial tokens too)
    Route::post('auth/logout',  [AuthController::class, 'logout']);
    Route::post('auth/refresh', [AuthController::class, 'refresh']);
    Route::get('auth/me',       [AuthController::class, 'me']);

    // 2FA — verify accepts partial token; setup/disable require full token.
    // Throttled: a TOTP code is only 6 digits (1M possibilities) — without a
    // rate limit it's crackable well within its ~30s validity window.
    Route::post('auth/2fa/verify', [TwoFactorController::class, 'verify'])->middleware('throttle:5,1');
    Route::middleware('require.2fa')->group(function () {
        Route::get('auth/2fa/setup',            [TwoFactorController::class, 'setup']);
        Route::post('auth/2fa/setup/confirm',   [TwoFactorController::class, 'confirmSetup'])->middleware('throttle:5,1');
        Route::delete('auth/2fa/setup',         [TwoFactorController::class, 'disable']);
        Route::patch('profile',                 [AuthController::class, 'updateProfile']);
        Route::post('profile/password',         [AuthController::class, 'changePassword']);
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

            // Properties this account is attached to (switcher) — for both roles
            Route::get('my-properties', [MyPropertiesController::class, 'index']);

            // Rooms (read for all staff)
            Route::get('rooms', [RoomController::class, 'index']);

            // Watchlist hits
            Route::get('watchlist-hits',                         [WatchlistHitController::class, 'index']);
            Route::post('watchlist-hits/{id}/acknowledge',       [WatchlistHitController::class, 'acknowledge']);

            // Check-ins (read for all staff)
            Route::get('check-ins',      [CheckInController::class, 'index']);
            Route::get('check-ins/{id}', [CheckInController::class, 'show']);

            // OCR scan status
            Route::get('scans/{scan_id}/status', [ScanController::class, 'status']);

            // ── Subscription-gated: write operations ──────────────────────
            Route::middleware('subscription.active')->group(function () {
                Route::post('check-ins',                                    [CheckInController::class, 'store']);
                Route::patch('check-ins/{id}',                              [CheckInController::class, 'update']);
                Route::post('check-ins/{id}/complete',                      [CheckInController::class, 'complete']);
                Route::post('check-ins/{id}/checkout',                      [CheckInController::class, 'checkout']);
                Route::post('check-ins/{id}/cancel',                        [CheckInController::class, 'cancel']);
                Route::post('check-ins/{check_in_id}/guests',               [GuestController::class, 'store']);
                Route::patch('check-ins/{check_in_id}/guests/{guest_id}',   [GuestController::class, 'update']);
                Route::delete('check-ins/{check_in_id}/guests/{guest_id}',  [GuestController::class, 'destroy']);
                Route::post('check-ins/{check_in_id}/scans',                [ScanController::class, 'store']);
            });

            // Hotel profile (read) — both roles need this to print fiches de police
            Route::get('profile', [HotelProfileController::class, 'show']);

            // ── Hotel admin only (tenant-aware) ────────────────────────────
            Route::middleware('role:hotel_admin')->group(function () {
                // Hotel profile (write)
                Route::patch('profile',      [HotelProfileController::class, 'update']);

                // Staff management
                Route::get('users',          [HotelUserController::class, 'index']);
                Route::post('users',         [HotelUserController::class, 'store']);
                Route::patch('users/{id}',   [HotelUserController::class, 'update']);
                Route::delete('users/{id}',  [HotelUserController::class, 'destroy']);
                Route::post('users/{id}/resend-invite', [HotelUserController::class, 'resendInvite']);

                // Staff activity feed
                Route::get('activity', [ActivityLogController::class, 'index']);

                // Check-in deletion — admin only, any status (soft delete)
                Route::delete('check-ins/{id}', [CheckInController::class, 'destroy']);

                // Room CRUD (write)
                Route::post('rooms',         [RoomController::class, 'store']);
                Route::patch('rooms/{id}',   [RoomController::class, 'update']);
                Route::delete('rooms/{id}',  [RoomController::class, 'destroy']);
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

    // Invoice history + payment (Flouci/virement) — hotel_admin only, matching
    // the billing-tab access level elsewhere. Org-scoped, not tenant-scoped:
    // admin-created invoices are org-level and must be reachable before any
    // property exists.
    Route::prefix('hotel')
        ->middleware(['role:hotel_admin'])
        ->group(function () {
            Route::get('invoices',                  [SubscriptionController::class, 'invoices']);
            Route::get('invoices/{id}/pdf',          [SubscriptionController::class, 'downloadInvoicePdf']);
            Route::post('payments/initiate',         [PaymentController::class, 'initiate']);
            Route::get('payments/{id}/verify',       [PaymentController::class, 'verify']);
            Route::post('payments/declare-virement', [PaymentController::class, 'declareVirement']);
        });

    // Onboarding + org management — hotel_admin only, no tenant needed
    Route::prefix('hotel')
        ->middleware(['role:hotel_admin'])
        ->group(function () {

            // Onboarding (works before first property exists)
            Route::get('onboarding/status',    [OnboardingController::class, 'status']);
            Route::post('onboarding/complete', [OnboardingController::class, 'complete']);

            // Organization info & multi-property management
            Route::get('organization',                                     [OrganizationController::class, 'show']);
            Route::patch('organization',                                   [OrganizationController::class, 'update']);
            Route::get('organization/properties',                          [OrganizationController::class, 'properties']);
            Route::post('organization/properties',                         [OrganizationController::class, 'addProperty']);
            Route::patch('organization/properties/{id}',                   [OrganizationController::class, 'updateProperty']);
            Route::delete('organization/properties/{id}',                  [OrganizationController::class, 'deleteProperty']);
            Route::get('organization/properties/{id}/rooms',               [OrganizationController::class, 'propertyRooms']);
            Route::post('organization/properties/{id}/rooms',              [OrganizationController::class, 'addPropertyRoom']);
            Route::patch('organization/properties/{id}/rooms/{roomId}',    [OrganizationController::class, 'updatePropertyRoom']);
            Route::delete('organization/properties/{id}/rooms/{roomId}',   [OrganizationController::class, 'deletePropertyRoom']);
        });

    /*
    |----------------------------------------------------------------------
    | Authority Routes (read-only)
    |----------------------------------------------------------------------
    */
    Route::prefix('authority')
        ->middleware(['role:authority_user', 'require.2fa', 'authority.credential', 'throttle:60,1'])
        ->group(function () {
            Route::get('dashboard',        [AuthorityDashboardController::class, 'dashboard']);
            Route::get('alerts',           [AuthorityDashboardController::class, 'alerts']);
            Route::get('activity',         [AuthorityDashboardController::class, 'activity']);
            Route::get('search',           [AuthoritySearchController::class, 'search']);
            Route::get('recent-check-ins', [AuthoritySearchController::class, 'recentCheckIns']);
            Route::get('guests/{id}',      [AuthoritySearchController::class, 'show']);
            Route::get('hotels',                    [AuthoritySearchController::class, 'hotels']);
            Route::get('hotels/{id}',               [AuthoritySearchController::class, 'showHotel']);
            Route::get('hotels/{id}/check-ins',     [AuthoritySearchController::class, 'hotelCheckIns']);
            Route::get('watchlist',                    [WatchlistController::class, 'index']);
            Route::post('watchlist',                   [WatchlistController::class, 'store']);
            Route::patch('watchlist/{id}',             [WatchlistController::class, 'update']);
            Route::delete('watchlist/{id}',            [WatchlistController::class, 'destroy']);
            Route::post('watchlist/import',            [WatchlistController::class, 'import']);
            Route::get('watchlist/template',           [WatchlistController::class, 'template']);
            Route::get('guests/{id}/export/pdf',   [ExportController::class, 'guestPdf']);
            Route::get('export/stays',             [ExportController::class, 'staysCsv']);
        });

    /*
    |----------------------------------------------------------------------
    | Platform Admin Routes
    |----------------------------------------------------------------------
    */
    Route::prefix('admin')
        ->middleware(['role:platform_admin', 'throttle:60,1'])
        ->group(function () {

            Route::get('dashboard', [HotelAdminController::class, 'dashboard']);
            Route::get('search',    [HotelAdminController::class, 'search']);

            // Hébergeurs (Organization — société/particulier)
            Route::get('hosts',              [OrganizationAdminController::class, 'index']);
            Route::post('hosts',             [OrganizationAdminController::class, 'store']);
            Route::get('hosts/{id}',         [OrganizationAdminController::class, 'show']);
            Route::patch('hosts/{id}',       [OrganizationAdminController::class, 'update']);
            Route::delete('hosts/{id}',      [OrganizationAdminController::class, 'destroy']);
            Route::post('hosts/{id}/suspend',  [OrganizationAdminController::class, 'suspend']);
            Route::post('hosts/{id}/activate', [OrganizationAdminController::class, 'activate']);

            // Hotels (établissements)
            Route::get('hotels',            [HotelAdminController::class, 'index']);
            Route::post('hotels',           [HotelAdminController::class, 'store']);
            Route::get('hotels/{id}',       [HotelAdminController::class, 'show']);
            Route::patch('hotels/{id}',     [HotelAdminController::class, 'update']);
            Route::delete('hotels/{id}',    [HotelAdminController::class, 'destroy']);
            Route::post('hotels/{id}/suspend',  [HotelAdminController::class, 'suspend']);
            Route::post('hotels/{id}/activate', [HotelAdminController::class, 'activate']);

            // Hotel users (scoped, used by the hotel detail panel)
            Route::get('hotels/{hotel_id}/users',  [HotelAdminController::class, 'getUsers']);
            Route::post('hotels/{hotel_id}/users', [HotelAdminController::class, 'createUser']);

            // Utilisateurs — vue globale (tous hébergeurs/établissements confondus)
            Route::get('users',                    [PlatformUserAdminController::class, 'index']);
            Route::post('users',                   [PlatformUserAdminController::class, 'store']);
            Route::patch('users/{id}',             [PlatformUserAdminController::class, 'update']);
            Route::delete('users/{id}',            [PlatformUserAdminController::class, 'destroy']);
            Route::post('users/{id}/resend-invite',[PlatformUserAdminController::class, 'resendInvite']);

            // Subscriptions & invoices — hébergeur-scoped (subscriptions/invoices are org-level;
            // no hotel-scoped equivalent exists anymore — every hotel now requires an organization).
            Route::get('hosts/{host_id}/subscriptions',        [SubscriptionAdminController::class, 'indexForHost']);
            Route::post('hosts/{host_id}/subscriptions',       [SubscriptionAdminController::class, 'storeForHost']);
            Route::patch('hosts/{host_id}/subscriptions/{id}', [SubscriptionAdminController::class, 'updateForHost']);
            Route::get('hosts/{host_id}/invoices',             [SubscriptionAdminController::class, 'invoicesForHost']);
            Route::post('hosts/{host_id}/invoices',            [SubscriptionAdminController::class, 'createInvoiceForHost']);
            Route::patch('hosts/{host_id}/invoices/{id}',      [SubscriptionAdminController::class, 'updateInvoiceForHost']);
            Route::delete('hosts/{host_id}/invoices/{id}',     [SubscriptionAdminController::class, 'destroyInvoiceForHost']);
            Route::get('hosts/{host_id}/invoices/{id}/pdf',    [SubscriptionAdminController::class, 'downloadInvoicePdf']);
            Route::get('invoices',                             [SubscriptionAdminController::class, 'allInvoices']);

            // Manual bank-transfer (virement) validation — hébergeur declares via
            // POST /hotel/payments/declare-virement, admin confirms or rejects here.
            Route::post('payments/{payment_id}/validate-virement', [SubscriptionAdminController::class, 'validateVirement']);
            Route::post('payments/{payment_id}/reject-virement',   [SubscriptionAdminController::class, 'rejectVirement']);

            // Authority users
            Route::get('authority-users',       [AuthorityAdminController::class, 'index']);
            Route::post('authority-users',      [AuthorityAdminController::class, 'store']);
            Route::patch('authority-users/{id}',[AuthorityAdminController::class, 'update']);
            Route::delete('authority-users/{id}',[AuthorityAdminController::class, 'destroy']);

            // Authority organizations (police / immigration / ministère...)
            Route::get('authority-organizations',       [AuthorityAdminController::class, 'organizations']);
            Route::post('authority-organizations',      [AuthorityAdminController::class, 'createOrganization']);
            Route::patch('authority-organizations/{id}',[AuthorityAdminController::class, 'updateOrganization']);
            Route::delete('authority-organizations/{id}',[AuthorityAdminController::class, 'destroyOrganization']);

            // Audit logs
            Route::get('audit-logs',             [AuditLogController::class, 'index']);
            Route::get('audit-logs/{id}',        [AuditLogController::class, 'show']);
            Route::get('authority-search-logs',  [AuditLogController::class, 'searchLogs']);

            // Platform settings (payment methods, Flouci config, RIB)
            Route::get('platform-settings',      [PlatformSettingController::class, 'show']);
            Route::patch('platform-settings',    [PlatformSettingController::class, 'update']);

            // Payments (read-only ledger)
            Route::get('payments',               [PlatformSettingController::class, 'payments']);

            // Subscription plans management (pricing + trilingual marketing content)
            Route::get('plans',                  [PlanAdminController::class, 'index']);
            Route::post('plans',                 [PlanAdminController::class, 'store']);
            Route::patch('plans/{id}',           [PlanAdminController::class, 'update']);
            Route::delete('plans/{id}',          [PlanAdminController::class, 'destroy']);

            // Email templates
            Route::get('emails',                 [EmailTemplateAdminController::class, 'index']);
            Route::patch('emails/{key}',         [EmailTemplateAdminController::class, 'update']);
            Route::get('emails/{key}/preview',   [EmailTemplateAdminController::class, 'preview']);
            Route::post('emails/{key}/send-test', [EmailTemplateAdminController::class, 'sendTest']);
            Route::post('emails/send-reminders', [EmailTemplateAdminController::class, 'sendReminders']);
        });
});
