<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sub_order_id')->unique()->constrained()->cascadeOnDelete(); // one payout per sub-order
            $table->unsignedBigInteger('amount_cents');     // owed to the vendor
            $table->unsignedBigInteger('commission_cents'); // kept by the platform
            $table->string('status')->default('pending');   // pending | paid
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
