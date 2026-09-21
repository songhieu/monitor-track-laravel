<?php

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => 'pong');

Route::middleware('auth:harness')->group(function () {
    // Lazy-loads the customer of each of the 30 orders: an N+1 of 30.
    Route::get('/orders', function () {
        $names = [];
        foreach (Order::all() as $order) {
            $names[] = $order->customer->name;
        }

        return ['orders' => count($names), 'user' => auth()->id()];
    });

    Route::get('/boom', function (Request $request) {
        throw new RuntimeException('boom for user '.auth()->id().' in '.$request->header('X-Request-Id'));
    });
});
