<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Delivery areas with their fee, managed in the admin panel. With none
        // active, checkout keeps asking for a free-text address with no fee.
        Schema::create('shipping_zones', function (Blueprint $table) {
            $table->id();
            $table->json('name');
            $table->decimal('fee', 14, 4)->default(0);
            // Delivery is free when the goods reach this amount. Empty: never free.
            $table->decimal('free_over', 14, 4)->nullable();
            $table->json('estimate')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->boolean('allows_pickup')->default(true);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('delivery_method', 16)->default('delivery');
            $table->foreignId('shipping_zone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            // Copied at checkout, so renaming or deleting a zone or branch later
            // does not change what the customer was told.
            $table->string('delivery_label')->nullable();
            $table->decimal('shipping_fee', 14, 4)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shipping_zone_id');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['delivery_method', 'delivery_label', 'shipping_fee']);
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('allows_pickup');
        });

        Schema::dropIfExists('shipping_zones');
    }
};
