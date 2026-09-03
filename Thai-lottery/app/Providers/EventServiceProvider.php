<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * Event to listener mappings.
     *
     * NOTE: Finance, lottery and payment events/listeners are registered here
     * as they are implemented in their respective phases.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        //
    ];

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
