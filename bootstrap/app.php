<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withCommands([
        // Registered explicitly rather than relying on discovery, so the console
        // surface of this application is something you can read in one place.
        __DIR__.'/../app/Console/Commands',
    ])
    ->withSchedule(function (Schedule $schedule): void {
        // ---------------------------------------------------------------------
        // Draw automation
        // ---------------------------------------------------------------------
        //
        // WHAT THIS FIXES
        // Phases 1 to 5.1 built a complete draw lifecycle - open, close, await
        // result, publish, settle - and nothing invoked it. config('lottery') had
        // declared draws on the 1st and 16th at 15:00 and an auto-close five minutes
        // before the draw since Phase 1, and no code read either value. A draw
        // existed only if somebody inserted a row and moved only if somebody called
        // a service by hand. This schedule is what makes the declared calendar real.
        //
        // ONE TASK, NOT FIVE
        // lottery:tick runs the five steps in order inside one process, because the
        // order matters within a single minute: a draw provisioned at 14:54 must be
        // able to open, and a draw whose cut-off is 14:55 must close, in the same
        // tick. Five separate scheduled tasks would spread that across five minutes
        // and make the effective cut-off drift.
        //
        // WHAT IS DELIBERATELY NOT SCHEDULED
        // Result publication. The official numbers come from outside this system and
        // config('lottery.results.require_admin_confirmation') says a human confirms
        // them, so publication is the operator command lottery:publish-result and
        // appears nowhere in this file.
        //
        // THE KILL SWITCH IS HONOURED HERE TOO
        // With config('lottery.automation.enabled') false, NO task is registered at
        // all - not a task that returns early. The commands carry the same guard, so
        // a manual run is also refused unless it is forced.
        if ((bool) config('lottery.automation.enabled', false) === true) {
            $expression = config('lottery.automation.tick_cron');
            $expression = is_string($expression) && trim($expression) !== '' ? trim($expression) : '* * * * *';

            $schedule->command('lottery:tick')
                ->cron($expression)
                // A tick that overruns must never run twice at once: two ticks could
                // both see the same draw as due. The lifecycle would refuse the
                // second transition anyway, but overlapping runs would fill the log
                // with refusals that look like defects.
                ->withoutOverlapping(10)
                // The scheduler must stay responsive for other work, and a tick that
                // settles a large draw is not instant.
                ->runInBackground()
                ->onOneServer()
                ->timezone(config('lottery.timezone', 'UTC'))
                ->appendOutputTo(storage_path('logs/lottery-tick.log'))
                ->description('Advance every draw that is due to its next lifecycle state');
        }
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'active' => \App\Http\Middleware\EnsureUserIsActive::class,
            'wallet.active' => \App\Http\Middleware\EnsureWalletIsActive::class,
            'draw.open' => \App\Http\Middleware\EnsureDrawIsOpen::class,
            'webhook.signature' => \App\Http\Middleware\VerifyWebhookSignature::class,
        ]);

        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // ---------------------------------------------------------------------
        // API exception rendering (Phase 4.4)
        // ---------------------------------------------------------------------
        //
        // Every exception that escapes an /api/* route is rendered through the same
        // envelope the controllers use, and through the same mapper, so a client never
        // has to parse two different error shapes.
        //
        // WHY THIS EXISTS AT ALL, GIVEN THE CONTROLLERS ALREADY CATCH
        // The purchase controller catches Throwable around the purchase call, so domain
        // failures are already mapped there. This handler covers what a controller
        // cannot: a failure BEFORE the controller runs (auth middleware, throttle
        // middleware, route resolution, request validation) and anything genuinely
        // unexpected. Without it, those cases would return Laravel's own default shapes -
        // and in a misconfigured environment, a stack trace.
        //
        // WHAT IS NEVER IN THE RESPONSE
        // No stack trace, no file path, no line number, no SQL, no exception class name,
        // no wallet id, no balance. The mapper builds the body from a fixed set of codes
        // and a whitelist of safe context keys; it never forwards an exception message it
        // did not author. This holds regardless of APP_DEBUG - a production-shaped
        // response is what the API returns even when a developer has debug enabled
        // locally, because an API client should never receive a body whose shape depends
        // on a server setting.
        //
        // The detail is not lost. It goes to the log, where operators can read it and
        // players cannot.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            // An HttpResponseException CARRIES a response that an earlier layer built
            // deliberately - the rate limiter's own 429, for example, which the limiter
            // registered in AppServiceProvider already emits in this project's envelope.
            // Re-mapping it here would discard that response and replace a correct 429
            // with a generic 500, so it is passed through untouched.
            if ($e instanceof \Illuminate\Http\Exceptions\HttpResponseException) {
                return null;
            }

            $mapper = app(\App\Http\Support\BetPurchaseErrorMapper::class);
            $mapped = $mapper->map($e);

            if ($mapped['status'] >= 500) {
                Log::error('api.unhandled_exception', [
                    'exception' => $e,
                    'path' => $request->path(),
                    'method' => $request->method(),
                    'user_id' => $request->user()?->getAuthIdentifier(),
                ]);
            }

            $headers = [];

            // Preserve Retry-After and X-RateLimit-* headers that the throttle
            // middleware attached, so a well-behaved client still learns when it may
            // retry even though the body has been reshaped.
            if ($e instanceof HttpExceptionInterface) {
                $headers = $e->getHeaders();
                unset($headers['Content-Type']);
            }

            return \App\Http\Responses\ApiResponse::error(
                $mapped['code'],
                $mapped['message'],
                $mapped['status'],
                $mapped['details'],
                $headers,
            );
        });

        // An unauthenticated API request must not be redirected to a login route.
        // Laravel's default is a redirect for non-JSON requests, and this project has no
        // web login route at all, so that default would turn a missing token into a
        // route-not-defined error instead of a clean 401.
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e): bool {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();
