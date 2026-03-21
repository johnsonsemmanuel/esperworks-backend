<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

/**
 * Private user channel — for per-user notifications.
 * Subscribed as: `private-App.Models.User.{id}`
 */
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/**
 * Private business channel — for business-level events (invoice sent, etc.)
 * Subscribed as: `private-business.{businessId}`
 */
Broadcast::channel('business.{businessId}', function ($user, $businessId) {
    return $user->businesses()
        ->where('businesses.id', $businessId)
        ->exists();
});
