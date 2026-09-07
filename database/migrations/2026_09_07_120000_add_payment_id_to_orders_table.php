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
        // The payment that settled the order — the one successful attempt whose money we keep.
        // Set exactly once, when the order goes pending → paid; any other attempt that later
        // succeeds at the provider is surplus and is refunded (see PaymentService::confirm()).
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('payment_id')->nullable()->after('status')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_id');
        });
    }
};
