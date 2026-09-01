<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\ViteException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * "Vite manifest not found" is true and useless here.
         *
         * Everywhere else that message means "run npm run build". In the panel
         * it cannot: there is no npm, no package.json and no stylesheet of its
         * own — PANEL_DOC Section 10 has it reuse the shop system's compiled
         * assets, copied in at deploy time. Somebody reading Laravel's own
         * message goes looking for a build script that was removed on purpose.
         *
         * The same shape of fix as `licence:keys` in the shop system, where the
         * bug turned out to be the message rather than the failure: say what is
         * missing, and say what to type.
         */
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->expectsJson()) {
                return null;
            }

            // Walked rather than type-hinted: the manifest is read while a
            // Blade view is rendering, and Blade rethrows anything that
            // happens there wrapped in a ViewException. Type-hinting the Vite
            // exception matched nothing at all, which is how the first version
            // of this went out doing nothing.
            for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof ViteException) {
                    return new Response(view('errors.assets-missing')->render(), 500);
                }
            }

            return null;
        });
    })->create();
