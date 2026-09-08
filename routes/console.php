<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// API tokens expire (config/sanctum.php); sweep the dead rows daily.
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Refunds run as jobs after the order is reversed; anything still pending is re-queued.
Schedule::command('payments:retry-refunds')->hourly();

// Unpaid orders expire (bazaar.pending_order_ttl_hours), releasing the coupon uses they reserved.
Schedule::command('orders:expire-pending')->hourly();

// Retention: the payment-event ledger is pruned after bazaar.payment_event_retention_days.
Schedule::command('model:prune')->daily();
