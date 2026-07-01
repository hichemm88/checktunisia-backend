<?php

use App\Http\Middleware\EnsureActiveSubscription;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\AuditRequestMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();

        $middleware->alias([
            'tenant'               => ResolveTenant::class,
            'subscription.active'  => EnsureActiveSubscription::class,
            'audit'                => AuditRequestMiddleware::class,
            'role'                 => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission'           => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission'   => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'data'   => null,
                    'errors' => [['code' => 'RESOURCE_NOT_FOUND', 'message' => 'Resource not found.', 'field' => null]],
                ], 404);
            }
        });

        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'data'   => null,
                    'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated.', 'field' => null]],
                ], 401);
            }
        });

        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, Request $request) {
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

        $exceptions->render(function (\Spatie\Permission\Exceptions\UnauthorizedException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'data'   => null,
                    'errors' => [['code' => 'PERMISSION_DENIED', 'message' => 'You do not have permission to perform this action.', 'field' => null]],
                ], 403);
            }
        });
    })
    ->create();
