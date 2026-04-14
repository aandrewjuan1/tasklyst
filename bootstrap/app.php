<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Throwable $exception, Request $request) {
            if ($request->expectsJson()) {
                return null;
            }

            if ($exception instanceof TokenMismatchException) {
                $statusCode = 419;
            } elseif ($exception instanceof HttpExceptionInterface) {
                $statusCode = $exception->getStatusCode();
            } else {
                return null;
            }

            $renderableStatusCodes = [403, 404, 419, 429, 500, 503];

            if (! in_array($statusCode, $renderableStatusCodes, true)) {
                return null;
            }

            return response()->view("errors.{$statusCode}", ['exception' => $exception], $statusCode);
        });
    })->create();
