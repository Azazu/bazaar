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
        // Snapshot of the variant's SKU at purchase time: the line must stay identifiable for
        // the vendor and for accounting even after the variant is edited or removed.
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('sku')->nullable()->after('product_variant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('sku');
        });
    }
};
