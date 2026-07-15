<?php

namespace Database\Seeders;

use App\Enums\CouponType;
use App\Models\Coupon;
use Illuminate\Database\Seeder;

class CouponSeeder extends Seeder
{
    public function run(): void
    {
        Coupon::firstOrCreate(['code' => 'WELCOME10'], [
            'type' => CouponType::Percent,
            'value' => 10,
            'min_subtotal_cents' => 0,
        ]);

        Coupon::firstOrCreate(['code' => 'SAVE5'], [
            'type' => CouponType::Fixed,
            'value' => 500,               // $5 off
            'min_subtotal_cents' => 2000, // requires a $20+ subtotal
        ]);
    }
}
