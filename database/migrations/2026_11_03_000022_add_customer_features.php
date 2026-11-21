<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reviews come from signed-in customers who bought the product; one each.
        Schema::table('reviews', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
            $table->unique(['product_id', 'user_id']);
            $table->index(['product_id', 'is_approved']);
        });

        // "Tell me when it is back": one row per address and product until sent.
        Schema::create('stock_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email');
            $table->string('locale', 5)->default('ar');
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'email']);
            $table->index(['product_id', 'notified_at']);
        });

        // Favorites of signed-in customers, so they follow them to every device.
        Schema::create('favorites', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['user_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorites');
        Schema::dropIfExists('stock_alerts');

        Schema::table('reviews', function (Blueprint $table) {
            $table->dropUnique(['product_id', 'user_id']);
            $table->dropIndex(['product_id', 'is_approved']);
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
