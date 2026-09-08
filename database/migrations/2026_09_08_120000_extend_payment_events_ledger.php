<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The ledger stays the idempotency guard (unique event_id) but now also says what each
        // event was and what we did with it, traceable to the payment and the order.
        Schema::table('payment_events', function (Blueprint $table) {
            $table->foreignId('payment_id')->nullable()->after('event_id')->constrained()->nullOnDelete();
            $table->string('gateway')->nullable()->after('payment_id');
            $table->string('transaction_id')->nullable()->after('gateway')->index();
            $table->string('type')->nullable()->after('transaction_id');          // e.g. payment.succeeded
            $table->string('outcome')->nullable()->after('type');                 // PaymentEvent::OUTCOME_*
            $table->timestamp('processed_at')->nullable()->after('outcome')->index();
        });

        // Rows from before this migration: processed when they were written.
        DB::table('payment_events')->whereNull('processed_at')->update(['processed_at' => DB::raw('created_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_events', function (Blueprint $table) {
            $table->dropIndex(['transaction_id']); // SQLite can't drop an indexed column while its index exists
            $table->dropIndex(['processed_at']);
            $table->dropConstrainedForeignId('payment_id');
            $table->dropColumn(['gateway', 'transaction_id', 'type', 'outcome', 'processed_at']);
        });
    }
};
