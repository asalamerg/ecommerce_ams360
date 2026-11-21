<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The history of one order: placed, status and payment changes, staff notes,
        // messages sent to the customer. Only "created" and status changes are
        // shown to the customer; notes stay internal.
        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32);
            $table->string('from', 32)->nullable();
            $table->string('to', 32)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['order_id', 'id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->index(['payment_status', 'created_at']);
            // The phone number's digits only, so 050-123-4567 is found by 0501234567.
            $table->string('phone_digits', 20)->nullable()->index();
        });

        DB::table('orders')->orderBy('id')->each(fn (object $order) => DB::table('orders')
            ->where('id', $order->id)
            ->update(['phone_digits' => preg_replace('/\D/', '', (string) $order->phone)]));
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['payment_status', 'created_at']);
            $table->dropIndex(['phone_digits']);
            $table->dropColumn('phone_digits');
        });

        Schema::dropIfExists('order_events');
    }
};
