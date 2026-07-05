<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Admin\HotelAdminController;
use App\Http\Controllers\Admin\SubscriptionAdminController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\AuthorityAdminController;
use App\Http\Controllers\Hotel\CheckInController;
use App\Http\Controllers\Hotel\GuestController;
use App\Http\Controllers\Hotel\ScanController;
use App\Http\Controllers\Hotel\RoomController;
use App\Http\Controllers\Hotel\DashboardController;
use App\Http\Controllers\Hotel\HotelProfileController;
use App\Http\Controllers\Hotel\HotelUserController;
use App\Http\Controllers\Hotel\MyPropertiesController;
use App\Http\Controllers\Hotel\OnboardingController;
use App\Http\Controllers\Hotel\OrganizationController;
use App\Http\Controllers\Authority\ExportController;
use App\Http\Controllers\Public\PublicRegistrationController;
use App\Http\Controllers\Public\PublicPlatformController;
use App\Http\Controllers\Admin\PlatformSettingController;
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

    // 2FA — verify accepts partial token; setup/disable require full token
    Route::post('auth/2fa/verify',          [TwoFactorController::class, 'verify']);
    Route::middleware('require.2fa')->group(function () {
        Route::get('auth/2fa/setup',            [TwoFactorController::class, 'setup']);
        Route::post('auth/2fa/setup/confirm',   [TwoFactorController::class, 'confirmSetup']);
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

            // Draft deletion — no subscription gate
            Route::delete('check-ins/{id}', [CheckInController::class, 'destroy']);

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

            // Payments (Flouci) — hotel-scoped (uses hotel for invoice lookup)
            Route::post('payments/initiate',   [PaymentController::class, 'initiate']);
            Route::get('payments/{id}/verify', [PaymentController::class, 'verify']);

            // ── Hotel admin only (tenant-aware) ────────────────────────────
            Route::middleware('role:hotel_admin')->group(function () {
                // Hotel profile
                Route::get('profile',        [HotelProfileController::class, 'show']);
                Route::patch('profile',      [HotelProfileController::class, 'update']);

                // Staff management
                Route::get('users',          [HotelUserController::class, 'index']);
                Route::post('users',         [HotelUserController::class, 'store']);
                Route::patch('users/{id}',   [HotelUserController::class, 'update']);
                Route::delete('users/{id}',  [HotelUserController::class, 'destroy']);
                Route::post('users/{id}/resend-invite', [HotelUserController::class, 'resendInvite']);

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
        ->middleware(['role:authority_user', 'authority.credential', 'throttle:60,1'])
        ->group(function () {
            Route::get('dashboard',        [AuthorityDashboardController::class, 'dashboard']);
            Route::get('alerts',           [AuthorityDashboardController::class, 'alerts']);
            Route::get('activity',         [AuthorityDashboardController::class, 'activity']);
            Route::get('search',           [AuthoritySearchController::class, 'search']);
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

            // Hotels
            Route::get('hotels',            [HotelAdminController::class, 'index']);
            Route::post('hotels',           [HotelAdminController::class, 'store']);
            Route::get('hotels/{id}',       [HotelAdminController::class, 'show']);
            Route::patch('hotels/{id}',     [HotelAdminController::class, 'update']);
            Route::post('hotels/{id}/suspend',  [HotelAdminController::class, 'suspend']);
            Route::post('hotels/{id}/activate', [HotelAdminController::class, 'activate']);

            // Hotel users
            Route::get('hotels/{hotel_id}/users',  [HotelAdminController::class, 'getUsers']);
            Route::post('hotels/{hotel_id}/users', [HotelAdminController::class, 'createUser']);

            // Subscriptions
            Route::get('hotels/{hotel_id}/subscriptions',        [SubscriptionAdminController::class, 'index']);
            Route::post('hotels/{hotel_id}/subscriptions',       [SubscriptionAdminController::class, 'store']);
            Route::patch('hotels/{hotel_id}/subscriptions/{id}', [SubscriptionAdminController::class, 'update']);

            // Invoices
            Route::get('hotels/{hotel_id}/invoices',        [SubscriptionAdminController::class, 'invoices']);
            Route::post('hotels/{hotel_id}/invoices',       [SubscriptionAdminController::class, 'createInvoice']);
            Route::patch('hotels/{hotel_id}/invoices/{id}', [SubscriptionAdminController::class, 'updateInvoice']);

            // Authority users
            Route::get('authority-users',       [AuthorityAdminController::class, 'index']);
            Route::post('authority-users',      [AuthorityAdminController::class, 'store']);
            Route::patch('authority-users/{id}',[AuthorityAdminController::class, 'update']);

            // Organizations
            Route::get('organizations',   [AuthorityAdminController::class, 'organizations']);
            Route::post('organizations',  [AuthorityAdminController::class, 'createOrganization']);

            // Audit logs
            Route::get('audit-logs',             [AuditLogController::class, 'index']);
            Route::get('audit-logs/{id}',        [AuditLogController::class, 'show']);
            Route::get('authority-search-logs',  [AuditLogController::class, 'searchLogs']);

            // Platform settings (payment methods, Flouci config, RIB)
            Route::get('platform-settings',      [PlatformSettingController::class, 'show']);
            Route::patch('platform-settings',    [PlatformSettingController::class, 'update']);

            // Subscription plans management
            Route::get('plans',                  [PlatformSettingController::class, 'listPlans']);
            Route::patch('plans/{id}',           [PlatformSettingController::class, 'updatePlan']);
        });
});
