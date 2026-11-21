<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Kept equal to the sum of the user's point_transactions by PointsLedger.
            $table->unsignedInteger('points_balance')->default(0);
        });

        Schema::table('orders', function (Blueprint $table) {
            // The money value of points_used, taken off the total like a coupon.
            $table->decimal('points_discount', 14, 4)->default(0)->after('discount_total');
        });

        Schema::create('point_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->integer('points');
            $table->unsignedInteger('balance_after');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('created_at');
            $table->index(['user_id', 'id']);
            // An order earns, redeems, refunds and reverses at most once each.
            $table->unique(['order_id', 'type']);
        });

        Schema::create('loyalty_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_enabled')->default(false);
            $table->decimal('spend_per_point', 14, 2)->default(100);
            $table->decimal('point_value', 14, 4)->default(1);
            $table->unsignedInteger('min_redeem_points')->default(0);
            $table->unsignedTinyInteger('max_redeem_percent')->default(50);
            $table->timestamps();
        });

        DB::table('loyalty_settings')->insert([
            'id' => 1,
            'is_enabled' => false,
            'spend_per_point' => 100,
            'point_value' => 1,
            'min_redeem_points' => 0,
            'max_redeem_percent' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_settings');
        Schema::dropIfExists('point_transactions');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('points_discount');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('points_balance');
        });
    }
};
