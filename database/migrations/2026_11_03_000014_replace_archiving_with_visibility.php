<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Archiving is replaced by two clear choices: hide a product or category
 * (is_active = false), or delete it for good. See CatalogController.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A deleted product keeps its stock history: the movement keeps the name
        // and loses only the link, instead of blocking the delete.
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->change();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            $table->string('product_name')->nullable()->after('product_id');
        });

        // Items archived before this change come back as hidden, so they show in
        // the admin lists and can be shown again or deleted.
        DB::table('products')->whereNotNull('deleted_at')->update(['deleted_at' => null, 'is_active' => false]);
        DB::table('categories')->whereNotNull('deleted_at')->update(['deleted_at' => null, 'is_active' => false]);
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropColumn('product_name');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable(false)->change();
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
        });
    }
};
