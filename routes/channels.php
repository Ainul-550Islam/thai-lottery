<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Authorization for private/presence broadcast channels. Only the owner may subscribe to
| their own user channel; a foreign caller yields false and the subscription is refused
| before any payload is delivered.
|
*/

Broadcast::channel('App.Models.User.{id}', function (User $user, string $id): bool {
    return (int) $user->getKey() === (int) $id;
});
