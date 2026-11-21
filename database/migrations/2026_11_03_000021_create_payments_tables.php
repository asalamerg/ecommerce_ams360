<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One attempt to pay an order online. An order can have several (a failed
        // card, then a retry); at most one ends up paid.
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('gateway', 40);
            // created → pending → paid | failed | cancelled; paid → refunded.
            // "review" when the provider reported something that does not match
            // (amount, currency, a paid order that was already cancelled).
            $table->string('status', 16)->default('created');
            // Copied from the order when the attempt starts. What the provider
            // reports must match these exactly before anything is marked paid.
            $table->decimal('amount', 14, 4);
            $table->string('currency', 3);
            $table->string('provider_reference')->nullable();
            // Sent with the create request, so a retried request never charges twice.
            $table->uuid('idempotency_key')->unique();
            // The customer's way back from the payment page; only its hash is kept.
            $table->string('return_token_hash', 64);
            $table->string('failure_reason')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'provider_reference']);
            $table->index(['order_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        // Every notification a provider sent. The unique key makes a replayed or
        // repeated notification a no-op.
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 40);
            $table->string('event_id', 191);
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->char('payload_hash', 64);
            $table->string('result', 32)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['gateway', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('payments');
    }
};
