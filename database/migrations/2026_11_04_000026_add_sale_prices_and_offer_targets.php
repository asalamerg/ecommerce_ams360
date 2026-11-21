<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The price a product sells at right now, worked out by ProductPricing from its
 * own discount and the running offers, so listings can sort and filter by it.
 * Offers can also cover a whole brand.
 *
 * Guarded with hasColumn so it is safe on a database that already has them.
 * The values are filled by `php artisan store:refresh-prices`, which also runs
 * every minute from the scheduler.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'sale_price')) {
                // Null when the product sells at its price.
                $table->decimal('sale_price', 14, 4)->nullable()->after('discount_ends_at');
            }

            if (! Schema::hasColumn('products', 'sale_ends_at')) {
                $table->timestamp('sale_ends_at')->nullable()->after('sale_price');
            }

            if (! Schema::hasColumn('products', 'offer_id')) {
                // The offer that gives the sale price; null for the product's own discount.
                $table->foreignId('offer_id')->nullable()->after('sale_ends_at')->constrained('offers')->nullOnDelete();
            }
        });

        if (! Schema::hasIndex('products', 'products_sale_price_index')) {
            Schema::table('products', function (Blueprint $table) {
                $table->index('sale_price');
            });
        }

        Schema::table('offers', function (Blueprint $table) {
            if (! Schema::hasColumn('offers', 'brand_id')) {
                $table->foreignId('brand_id')->nullable()->after('category_id')->constrained('brands')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('brand_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['sale_price']);
            $table->dropConstrainedForeignId('offer_id');
            $table->dropColumn(['sale_price', 'sale_ends_at']);
        });
    }
};
