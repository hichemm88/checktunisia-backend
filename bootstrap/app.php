<?php

use App\Http\Middleware\AuditRequestMiddleware;
use App\Http\Middleware\EnsureActiveSubscription;
use App\Http\Middleware\EnsureAuthorityCredentialValid;
use App\Http\Middleware\EnsureOrgOwner;
use App\Http\Middleware\EnsurePlatformAdmin2FA;
use App\Http\Middleware\Require2FA;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SentryContext;
use App\Http\Middleware\VerifyAiTrackingSecret;
use App\Http\Middleware\VerifyWhatsappWorker;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Sentry\Laravel\Integration as SentryIntegration;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
        // Routes publiées hors de notre contrôle, donc hors du versionnage de
        // l'API : aujourd'hui le lien « Consulter la fiche » des messages
        // WhatsApp, dont l'URL est figée chez Meta. Voir routes/public.php.
        then: function () {
            Route::middleware('api')->group(base_path('routes/public.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Conservative security headers on every API response (defence in depth).
        // throttle:api — repli global (API-01). Le périmètre /hotel/* n'avait
        // AUCUNE limitation, upload de scans compris. Les groupes qui ont déjà
        // leur propre throttle (auth, autorité, admin) conservent le leur : les
        // deux s'appliquent, le plus strict l'emporte de fait.
        $middleware->api(append: [
            SecurityHeaders::class,
            ThrottleRequests::class.':api',
            SentryContext::class,
        ]);

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'subscription.active' => EnsureActiveSubscription::class,
            'authority.credential' => EnsureAuthorityCredentialValid::class,
            'org.owner' => EnsureOrgOwner::class,
            'admin.2fa' => EnsurePlatformAdmin2FA::class,
            'require.2fa' => Require2FA::class,
            'audit' => AuditRequestMiddleware::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            // MODULE PROVISOIRE — relais WhatsApp (à retirer après homologation MI).
            'whatsapp.worker' => VerifyWhatsappWorker::class,
            // Ingestion interne du tracking des coûts IA (fonction serverless Vercel).
            'ai.tracking.secret' => VerifyAiTrackingSecret::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Remontée des exceptions vers Sentry. Inerte tant que
        // SENTRY_LARAVEL_DSN n'est pas défini (cas du local et des tests).
        // Le filtrage des données personnelles est dans config/sentry.php.
        SentryIntegration::handles($exceptions);

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'data' => null,
                    'errors' => [['code' => 'RESOURCE_NOT_FOUND', 'message' => 'Resource not found.', 'field' => null]],
                ], 404);
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'data' => null,
                    'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated.', 'field' => null]],
                ], 401);
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                $errors = [];
                foreach ($e->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $errors[] = ['code' => 'VALIDATION_ERROR', 'message' => $message, 'field' => $field];
                    }
                }

                return response()->json(['data' => null, 'errors' => $errors], 422);
            }
        });

        $exceptions->render(function (UnauthorizedException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'data' => null,
                    'errors' => [['code' => 'PERMISSION_DENIED', 'message' => 'You do not have permission to perform this action.', 'field' => null]],
                ], 403);
            }
        });
    })
    ->create();
