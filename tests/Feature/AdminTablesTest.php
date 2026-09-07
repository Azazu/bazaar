<?php

use App\Models\Coupon;
use App\Models\Order;

it('formats money in the admin tables', function () {
    Order::factory()->create(['total_cents' => 123456, 'currency' => 'USD']);
    Coupon::factory()->create(['value' => 10, 'min_subtotal_cents' => 5000]);

    $this->actingAs(admin())->get('/admin/orders')->assertOk()->assertSee('$1,234.56');
    $this->actingAs(admin())->get('/admin/coupons')->assertOk()->assertSee('10%')->assertSee('$50.00');
});
