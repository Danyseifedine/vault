<?php

use App\Http\Middleware\EnsureOnboardingIsComplete;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RefuseRecoveryCodes;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(prepend: [
            // Closes Fortify's recovery-code endpoints, which its vendor route
            // file registers whether we want them or not.
            RefuseRecoveryCodes::class,
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'onboarded' => EnsureOnboardingIsComplete::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * A failed validation flashes old input into the session so the form
         * can refill - and the sessions table is plaintext at rest. For these
         * fields "refill on error" would mean writing a secret, a pasted .env,
         * or a PIN to disk unencrypted (rule 3), so they are never flashed.
         */
        $exceptions->dontFlash([
            'current_password',
            'password',
            'password_confirmation',
            'value',
            'contents',
            'pin',
        ]);
    })->create();
