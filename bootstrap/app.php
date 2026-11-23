<?php

use App\Http\Middleware\CheckRole;
use App\Http\Middleware\CheckTokenInactivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'backend-api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Agar Laravel percaya pada Proxy Vercel
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'role' => CheckRole::class,
            'inactivity' => CheckTokenInactivity::class,
        ]);

        $middleware->statefulApi();

        $middleware->redirectGuestsTo(
            fn ($request) => $request->is('backend-api/*')
                ? null
                : route('login')
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Diagnostic logging sementara untuk Vercel
        $exceptions->report(function (Throwable $e) {
            error_log('[SIAMAG_EXCEPTION] '.json_encode([
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        });

        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if (! $request->is('backend-api/*') && ! $request->expectsJson()) {
                return null;
            }

            // Biarkan Laravel menangani exception HTTP standar (401, 403, 404, 422, dst.)
            // agar status respons tidak berubah menjadi 500.
            if (
                $e instanceof HttpExceptionInterface
                || $e instanceof AuthenticationException
                || $e instanceof AuthorizationException
                || $e instanceof ModelNotFoundException
                || $e instanceof ValidationException
            ) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => $e->getMessage(),
                'exception' => class_basename($e),
                'file' => basename($e->getFile()),
                'line' => $e->getLine(),
            ], 500);
        });
    })->create();
