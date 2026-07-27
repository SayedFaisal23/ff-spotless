<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if (! $request->hasHeader('X-Inertia')) {
                return null;
            }

            return back()
                ->withErrors([
                    'rate_limit' => 'Terlalu banyak percubaan. Sila tunggu satu minit sebelum mencuba semula.',
                ])
                ->withHeaders($exception->getHeaders());
        });

        $exceptions->render(function (PostTooLargeException $exception, Request $request) {
            if (! $request->hasHeader('X-Inertia')) {
                return null;
            }

            return back()->withErrors([
                'photos' => 'Jumlah saiz foto melebihi had pelayan. Kurangkan bilangan atau saiz foto dan cuba lagi.',
            ]);
        });
    })->create();

/*
 * Hostinger deployment keeps the Laravel application beside public_html:
 *
 * /home/<account>/ffspotless
 * /home/<account>/private/.env
 * /home/<account>/public_html
 *
 * Prefer the private environment file when it is present, while retaining
 * the normal project-root .env behavior for local development and Docker.
 */
$privateEnvironmentPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'private';

if (is_file($privateEnvironmentPath.DIRECTORY_SEPARATOR.'.env')) {
    $app->useEnvironmentPath($privateEnvironmentPath);
}

return $app;
