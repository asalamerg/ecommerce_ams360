<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A percentage discount the admin sets on a product, with an optional end date,
 * and the categories the admin picks to show as sections on the home page.
 *
 * Guarded with hasColumn so it is safe on a database that already has them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'discount_percent')) {
                // 0 means no discount. The price column stays the price before it.
                $table->unsignedTinyInteger('discount_percent')->default(0)->after('price');
            }

            if (! Schema::hasColumn('products', 'discount_ends_at')) {
                // Empty means the discount runs until the admin removes it.
                $table->timestamp('discount_ends_at')->nullable()->after('discount_percent');
            }
        });

        if (! Schema::hasIndex('products', 'products_discount_percent_index')) {
            Schema::table('products', function (Blueprint $table) {
                $table->index('discount_percent');
            });
        }

        Schema::table('categories', function (Blueprint $table) {
            if (! Schema::hasColumn('categories', 'show_on_home')) {
                $table->boolean('show_on_home')->default(false)->after('is_active');
            }
        });

        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'original_price')) {
                // The price before the product discount; null when there was none.
                $table->decimal('original_price', 14, 4)->nullable()->after('price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('original_price');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('show_on_home');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['discount_percent']);
            $table->dropColumn(['discount_percent', 'discount_ends_at']);
        });
    }
};
