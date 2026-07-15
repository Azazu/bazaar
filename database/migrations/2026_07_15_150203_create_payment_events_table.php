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
        // Idempotency ledger: one row per processed gateway event. A duplicate
        // event id can't be inserted (unique), so the handler skips it.
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_events');
    }
};
