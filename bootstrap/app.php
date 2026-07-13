<?php

use App\Http\Middleware\AuditRequestMiddleware;
use App\Http\Middleware\EnsureActiveSubscription;
use App\Http\Middleware\EnsureAuthorityCredentialValid;
use App\Http\Middleware\Require2FA;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerifyWhatsappWorker;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Conservative security headers on every API response (defence in depth).
        $middleware->api(append: [
            SecurityHeaders::class,
        ]);

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'subscription.active' => EnsureActiveSubscription::class,
            'authority.credential' => EnsureAuthorityCredentialValid::class,
            'require.2fa' => Require2FA::class,
            'audit' => AuditRequestMiddleware::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            // MODULE PROVISOIRE — relais WhatsApp (à retirer après homologation MI).
            'whatsapp.worker' => VerifyWhatsappWorker::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
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
