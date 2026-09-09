<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

final class NamedRateLimitersTest extends TestCase
{
    public function test_all_production_named_rate_limiters_are_registered(): void
    {
        $limiters = [
            'api',
            'login',
            'bet',
            'deposit',
            'withdrawal',
            'webhook',
            'player-api',
            'player-bet-placement',
            'financial-critical',
        ];

        foreach ($limiters as $limiterName) {
            $limiter = RateLimiter::limiter($limiterName);
            $this->assertNotNull($limiter, "Rate limiter [{$limiterName}] must be registered in RateLimiter.");
        }
    }
}
