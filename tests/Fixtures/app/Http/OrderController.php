<?php

namespace MonitorTrack\Tests\Fixtures\App\Http;

use MonitorTrack\Tests\Fixtures\App\Models\Order;

class OrderController
{
    /**
     * The classic N+1: one lazy-loaded customer query per order.
     *
     * @return list<string>
     */
    public function index(string $shop): array
    {
        $names = [];
        foreach (Order::query()->orderBy('id')->get() as $order) {
            $names[] = $order->customer->name;
        }

        return $names;
    }

    /**
     * Eager loading: two queries, whatever the number of orders.
     *
     * @return list<string>
     */
    public function eager(string $shop): array
    {
        $names = [];
        foreach (Order::query()->with('customer')->orderBy('id')->get() as $order) {
            $names[] = $order->customer->name;
        }

        return $names;
    }
}
