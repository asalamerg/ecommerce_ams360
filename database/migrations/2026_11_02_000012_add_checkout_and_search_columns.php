<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->text('search_name')->nullable();
            $table->text('search_body')->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('reference', 12)->nullable()->unique();
            $table->string('locale', 5)->default('ar');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->boolean('price_is_estimate')->default(false);
            $table->unsignedSmallInteger('stock_deducted')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['price_is_estimate', 'stock_deducted']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['reference']);
            $table->dropColumn(['reference', 'locale']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['search_name', 'search_body']);
        });
    }
};
