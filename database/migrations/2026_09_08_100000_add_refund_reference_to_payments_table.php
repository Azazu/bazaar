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
        // What the provider answered when we returned the money, for audit and reconciliation.
        Schema::table('payments', function (Blueprint $table) {
            $table->string('refund_reference')->nullable()->after('status');
            $table->timestamp('refunded_at')->nullable()->after('refund_reference');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['refund_reference', 'refunded_at']);
        });
    }
};
