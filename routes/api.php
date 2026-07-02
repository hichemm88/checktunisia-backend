<?php

use App\Http\Controllers\Auth\AuthController;
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
use App\Http\Controllers\Hotel\SubscriptionController;
use App\Http\Controllers\Authority\AuthoritySearchController;
use App\Http\Controllers\Authority\AuthorityDashboardController;
use App\Http\Controllers\Authority\WatchlistController;
use App\Http\Controllers\Hotel\WatchlistHitController;
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

/*
|--------------------------------------------------------------------------
| Authenticated Routes (all roles)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'audit'])->group(function () {

    // Auth
    Route::post('auth/logout',  [AuthController::class, 'logout']);
    Route::post('auth/refresh', [AuthController::class, 'refresh']);
    Route::get('auth/me',       [AuthController::class, 'me']);
    Route::patch('profile', [AuthController::class, 'updateProfile']);
    Route::post('profile/password', [AuthController::class, 'changePassword']);

    /*
    |----------------------------------------------------------------------
    | Hotel Staff Routes (hotel_admin + receptionist)
    |----------------------------------------------------------------------
    */
    Route::prefix('hotel')
        ->middleware(['role:hotel_admin|receptionist', 'tenant'])
        ->group(function () {

            // Dashboard
            Route::get('dashboard', [DashboardController::class, 'index']);

            // Subscription (read)
            Route::get('subscription', [SubscriptionController::class, 'current']);

            // Rooms (read for all staff)
            Route::get('rooms', [RoomController::class, 'index']);

            // Draft deletion — no subscription gate (allow cleanup even if sub expired)
            Route::delete('check-ins/{id}', [CheckInController::class, 'destroy']);
            // Watchlist hits (security alerts for hotel)
            Route::get('watchlist-hits',                         [WatchlistHitController::class, 'index']);
            Route::post('watchlist-hits/{id}/acknowledge',       [WatchlistHitController::class, 'acknowledge']);

            // Check-ins (read for all staff)
            Route::get('check-ins', [CheckInController::class, 'index']);
            Route::get('check-ins/{id}', [CheckInController::class, 'show']);

            // OCR scan status (no subscription gate — just viewing status)
            Route::get('scans/{scan_id}/status', [ScanController::class, 'status']);

            // ── Subscription-gated: write operations ──────────────────────
            Route::middleware('subscription.active')->group(function () {
                Route::post('check-ins', [CheckInController::class, 'store']);
                Route::patch('check-ins/{id}', [CheckInController::class, 'update']);
                Route::post('check-ins/{id}/complete', [CheckInController::class, 'complete']);
                Route::post('check-ins/{id}/checkout', [CheckInController::class, 'checkout']);
                Route::post('check-ins/{id}/cancel', [CheckInController::class, 'cancel']);

                // Guests
                Route::post('check-ins/{check_in_id}/guests', [GuestController::class, 'store']);
                Route::patch('check-ins/{check_in_id}/guests/{guest_id}', [GuestController::class, 'update']);
                Route::delete('check-ins/{check_in_id}/guests/{guest_id}', [GuestController::class, 'destroy']);

                // Passport scan upload
                Route::post('check-ins/{check_in_id}/scans', [ScanController::class, 'store']);
            });

            // ── Hotel admin only ───────────────────────────────────────────
            Route::middleware('role:hotel_admin')->group(function () {
                // Hotel profile (read available to all staff above, write admin only)
                Route::get('profile', [HotelProfileController::class, 'show']);
                Route::patch('profile', [HotelProfileController::class, 'update']);

                Route::get('users', [HotelUserController::class, 'index']);
                Route::post('users', [HotelUserController::class, 'store']);
                Route::patch('users/{id}', [HotelUserController::class, 'update']);
                Route::delete('users/{id}', [HotelUserController::class, 'destroy']);

                Route::post('rooms', [RoomController::class, 'store']);
                Route::patch('rooms/{id}', [RoomController::class, 'update']);
                Route::delete('rooms/{id}', [RoomController::class, 'destroy']);
            });
        });

    /*
    |----------------------------------------------------------------------
    | Authority Routes (read-only)
    |----------------------------------------------------------------------
    */
    Route::prefix('authority')
        ->middleware(['role:authority_user', 'authority.credential', 'throttle:60,1'])
        ->group(function () {
            // Dashboard (ministry = national stats, police = zone stats)
            Route::get('dashboard',        [AuthorityDashboardController::class, 'dashboard']);
            // Expiring documents alert feed
            Route::get('alerts',           [AuthorityDashboardController::class, 'alerts']);
            // Audit activity log (ministry=all, police=own)
            Route::get('activity',         [AuthorityDashboardController::class, 'activity']);
            // Guest search & profile
            Route::get('search',           [AuthoritySearchController::class, 'search']);
            Route::get('guests/{id}',      [AuthoritySearchController::class, 'show']);
            // Hotel directory
            Route::get('hotels',           [AuthoritySearchController::class, 'hotels']);
            Route::get('hotels/{id}',      [AuthoritySearchController::class, 'showHotel']);
            // Watchlist management
            Route::get('watchlist',                    [WatchlistController::class, 'index']);
            Route::post('watchlist',                   [WatchlistController::class, 'store']);
            Route::patch('watchlist/{id}',             [WatchlistController::class, 'update']);
            Route::delete('watchlist/{id}',            [WatchlistController::class, 'destroy']);
            Route::post('watchlist/import',            [WatchlistController::class, 'import']);
            Route::get('watchlist/template',           [WatchlistController::class, 'template']);
        });

    /*
    |----------------------------------------------------------------------
    | Platform Admin Routes
    |----------------------------------------------------------------------
    */
    Route::prefix('admin')
        ->middleware('role:platform_admin')
        ->group(function () {

            Route::get('dashboard', [HotelAdminController::class, 'dashboard']);

            // Hotels
            Route::get('hotels', [HotelAdminController::class, 'index']);
            Route::post('hotels', [HotelAdminController::class, 'store']);
            Route::get('hotels/{id}', [HotelAdminController::class, 'show']);
            Route::patch('hotels/{id}', [HotelAdminController::class, 'update']);
            Route::post('hotels/{id}/suspend', [HotelAdminController::class, 'suspend']);
            Route::post('hotels/{id}/activate', [HotelAdminController::class, 'activate']);

            // Hotel users
            Route::get('hotels/{hotel_id}/users', [HotelAdminController::class, 'getUsers']);
            Route::post('hotels/{hotel_id}/users', [HotelAdminController::class, 'createUser']);

            // Subscriptions
            Route::get('hotels/{hotel_id}/subscriptions', [SubscriptionAdminController::class, 'index']);
            Route::post('hotels/{hotel_id}/subscriptions', [SubscriptionAdminController::class, 'store']);
            Route::patch('hotels/{hotel_id}/subscriptions/{id}', [SubscriptionAdminController::class, 'update']);

            // Invoices
            Route::get('hotels/{hotel_id}/invoices', [SubscriptionAdminController::class, 'invoices']);
            Route::post('hotels/{hotel_id}/invoices', [SubscriptionAdminController::class, 'createInvoice']);
            Route::patch('hotels/{hotel_id}/invoices/{id}', [SubscriptionAdminController::class, 'updateInvoice']);

            // Authority users
            Route::get('authority-users', [AuthorityAdminController::class, 'index']);
            Route::post('authority-users', [AuthorityAdminController::class, 'store']);
            Route::patch('authority-users/{id}', [AuthorityAdminController::class, 'update']);

            // Organizations
            Route::get('organizations', [AuthorityAdminController::class, 'organizations']);
            Route::post('organizations', [AuthorityAdminController::class, 'createOrganization']);

            // Audit logs
            Route::get('audit-logs', [AuditLogController::class, 'index']);
            Route::get('audit-logs/{id}', [AuditLogController::class, 'show']);
            Route::get('authority-search-logs', [AuditLogController::class, 'searchLogs']);
        });
});
