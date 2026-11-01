<?php

use App\Http\Controllers\OutboundMessageController;
use App\Http\Middleware\AuthenticateOutboundToken;
use Illuminate\Support\Facades\Route;

// The API group has no session and no CSRF, and RedirectIfNotSecure is on the
// web group only. Throttling lives inside the one middleware so its order
// against authentication cannot be changed by route middleware priority
// (ZERO-131).
Route::post('/outbound', [OutboundMessageController::class, 'store'])
    ->middleware(AuthenticateOutboundToken::class)
    ->name('outbound.store');
