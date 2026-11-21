<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The colors a product comes in. Stock stays per product: colors are a
        // choice the customer makes, not separate stock lines.
        Schema::create('product_colors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->json('name');
            $table->char('hex', 7);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->index(['product_id', 'position']);
        });

        // One product may now sit in the cart once per color. The new unique
        // index is added first: it also starts with cart_id, so the foreign key
        // keeps an index while the old one is dropped (MySQL requires that).
        Schema::table('cart_items', function (Blueprint $table) {
            $table->foreignId('color_id')->nullable()->after('product_id')->constrained('product_colors')->cascadeOnDelete();
            $table->unique(['cart_id', 'product_id', 'color_id']);
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropUnique(['cart_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->unique(['cart_id', 'product_id']);
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropUnique(['cart_id', 'product_id', 'color_id']);
            $table->dropConstrainedForeignId('color_id');
        });

        Schema::dropIfExists('product_colors');
    }
};
