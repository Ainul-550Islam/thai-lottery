<?php

namespace App\Providers;

use App\Http\Responses\ApiResponse;
use App\Http\Support\BetPurchaseErrorMapper;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // NOTE (Payment phase): the PaymentGatewayInterface binding lives here
        // once App\Services\Payment\Gateway\* classes are implemented. It is
        // intentionally not registered yet so the container stays resolvable.
    }

    public function boot(): void
    {
        // Money is handled with bcmath strings; force a consistent scale.
        if (function_exists('bcscale')) {
            bcscale(2);
        }

        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        $this->registerRateLimiters();

        if ($this->app->environment('local')) {
            DB::whenQueryingForLongerThan(1000, function ($connection, $event): void {
                logger()->warning('Slow query detected.', [
                    'sql' => $event->sql,
                    'time' => $event->time,
                ]);
            });
        }
    }

    /**
     * Register the named rate limiters used by routes/api.php.
     *
     * config/security.php already declared these ceilings in Phase 1 and explicitly noted
     * that "Named limiters are registered from these values in a later phase". Phase 4.4 is
     * that phase. The numbers are read from config, never hard-coded here, so an operator
     * changes a limit through the existing RATE_LIMIT_* environment variables rather than by
     * editing application code.
     *
     * WHY THE `bet` LIMITER IS KEYED ON THE USER, NOT THE IP
     * Betting is an authenticated action. Keying on the IP would punish every player behind
     * one mobile carrier NAT for the behaviour of one of them, and would let a single
     * account bypass its own ceiling by rotating IPs. The user id is the only key that
     * matches what the limit is actually protecting. This is also exactly what
     * config('security.rate_limits.bet.by') already specified: 'user'.
     */
    private function registerRateLimiters(): void
    {
        $this->registerLoginLimiter();

        $apiPerMinute = (int) config('security.rate_limits.api.max_per_minute', 60);
        $betPerMinute = (int) config('security.rate_limits.bet.max_per_minute', 10);

        RateLimiter::for('api', function (Request $request) use ($apiPerMinute): Limit {
            $identifier = $request->user()?->getAuthIdentifier();

            // 'user_or_ip' per config: an authenticated caller is limited as themselves, and
            // an unauthenticated one - which on this surface means a request that will be
            // rejected by auth middleware anyway - is limited by IP so that unauthenticated
            // traffic cannot be used to exhaust a real user's allowance.
            return Limit::perMinute($apiPerMinute)
                ->by($identifier === null ? 'ip:'.$request->ip() : 'user:'.$identifier)
                ->response($this->throttleResponse());
        });

        RateLimiter::for('bet', function (Request $request) use ($betPerMinute): Limit {
            $identifier = $request->user()?->getAuthIdentifier();

            if ($identifier === null) {
                // Should not occur behind auth:sanctum. Falling back to the IP is the safe
                // direction: an unkeyed limiter would be no limiter at all.
                return Limit::perMinute($betPerMinute)
                    ->by('bet:ip:'.$request->ip())
                    ->response($this->throttleResponse());
            }

            return Limit::perMinute($betPerMinute)
                ->by('bet:user:'.$identifier)
                ->response($this->throttleResponse());
        });
    }

    /**
     * The `login` limiter used by the public token route.
     *
     * config/security.php already declared `rate_limits.login` with max_attempts,
     * decay_minutes and `by => 'email_and_ip'`, and nothing was reading it because the
     * project had no login route. Both halves of that key are used: the submitted
     * identifier (lower-cased so casing cannot multiply an attacker's allowance) and the
     * client IP. Keying on the identifier alone would let one host attack thousands of
     * accounts; keying on the IP alone would let a botnet attack one account.
     */
    private function registerLoginLimiter(): void
    {
        $maxAttempts = (int) config('security.rate_limits.login.max_attempts', 5);
        $decayMinutes = (int) config('security.rate_limits.login.decay_minutes', 15);

        RateLimiter::for('login', function (Request $request) use ($maxAttempts, $decayMinutes): array {
            $identifier = mb_strtolower(trim((string) $request->input('login', '')));

            return [
                Limit::perMinutes($decayMinutes, $maxAttempts)
                    ->by('login:id:'.sha1($identifier))
                    ->response($this->throttleResponse()),
                Limit::perMinutes($decayMinutes, $maxAttempts * 5)
                    ->by('login:ip:'.$request->ip())
                    ->response($this->throttleResponse()),
            ];
        });
    }

    /**
     * The throttled response, in the project's API envelope.
     *
     * Without this, Laravel returns its own plain `{"message": "Too Many Attempts."}` body,
     * which would be the one response on the whole surface that did not match the documented
     * envelope - so a client's error handling would break precisely when it is being rate
     * limited. The Retry-After header that the throttle middleware adds is preserved.
     */
    private function throttleResponse(): callable
    {
        return function (Request $request, array $headers = []): Response {
            return ApiResponse::error(
                BetPurchaseErrorMapper::CODE_RATE_LIMITED,
                'Too many requests. Please slow down and retry shortly.',
                429,
                [],
                $headers,
            );
        };
    }
}
