<?php

use App\Console\Commands\MockGatewayEventCommand;
use App\Http\Middleware\ResolveInstitution;
use App\Support\ApiResponse;
use App\Support\ErrorCode;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        MockGatewayEventCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'institution.resolve' => ResolveInstitution::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Every /api/* exception renders inside the standard error
        // envelope: {success:false, message, error:{code, details?}}.
        // Secret-free by construction: no trace, no input dump.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                ErrorCode::VALIDATION_FAILED,
                'The request is invalid.',
                422,
                $e->errors(),
            );
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            // Let the ValidationException renderer above own 422s (it
            // returns a response, so this closure never sees them — the
            // guard below is belt-and-braces for direct throws).
            if ($e instanceof ValidationException) {
                return null;
            }

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
            $status = max(400, min(599, (int) $status));

            $code = match (true) {
                $status === 401 => ErrorCode::UNAUTHENTICATED,
                $status === 403 => ErrorCode::FORBIDDEN,
                $status === 404 => ErrorCode::NOT_FOUND,
                $status === 405 => ErrorCode::METHOD_NOT_ALLOWED,
                $status === 409 => ErrorCode::CONFLICT,
                $status === 419 => ErrorCode::CSRF_TOKEN_MISMATCH,
                $status === 429 => ErrorCode::RATE_LIMITED,
                $status >= 500 => ErrorCode::INTERNAL_ERROR,
                default => ErrorCode::REQUEST_FAILED,
            };

            $message = match (true) {
                $status === 404 => 'Resource not found.',
                $status === 405 => 'Method not allowed.',
                $status === 429 => 'Too many requests.',
                $status >= 500 => 'Internal error.',
                default => 'The request failed.',
            };

            return ApiResponse::error($code, $message, $status);
        });
    })->create();
