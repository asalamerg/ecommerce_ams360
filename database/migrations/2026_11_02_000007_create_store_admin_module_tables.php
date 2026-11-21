<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->json('name');
            $table->json('description')->nullable();
            $table->string('logo_url')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::create('hero_slides', function (Blueprint $table) {
            $table->id();
            $table->string('image_url');
            $table->json('title');
            $table->json('subtitle')->nullable();
            $table->string('link_url')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->json('title');
            $table->json('content');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('discount_type', 16);
            $table->decimal('discount_value', 14, 4);
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->json('title');
            $table->json('description')->nullable();
            $table->string('image_url')->nullable();
            $table->string('discount_type', 16);
            $table->decimal('discount_value', 14, 4);
            $table->json('product_ids')->nullable();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('sim_plans', function (Blueprint $table) {
            $table->id();
            $table->string('carrier');
            $table->json('name');
            $table->decimal('data_gb', 8, 2)->nullable();
            $table->unsignedInteger('minutes')->nullable();
            $table->unsignedInteger('sms')->nullable();
            $table->decimal('monthly_price', 14, 4);
            $table->unsignedSmallInteger('contract_months')->default(0);
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->json('title');
            $table->json('description')->nullable();
            $table->string('icon')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->json('name');
            $table->json('address');
            $table->string('phone', 40)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->json('working_hours')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name');
            $table->string('email')->nullable();
            $table->string('phone', 40);
            $table->json('shipping_address')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('item_count')->default(0);
            $table->decimal('subtotal', 14, 4)->default(0);
            $table->decimal('discount_total', 14, 4)->default(0);
            $table->decimal('total', 14, 4)->default(0);
            $table->string('coupon_code')->nullable();
            $table->string('status', 24)->default('pending');
            $table->string('payment_method', 40)->nullable();
            $table->string('payment_status', 24)->default('unpaid');
            $table->string('payment_reference')->nullable();
            $table->unsignedInteger('points_used')->default(0);
            $table->unsignedInteger('points_earned')->default(0);
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->json('name');
            $table->decimal('price', 14, 4);
            $table->unsignedSmallInteger('quantity');
            $table->string('color_name')->nullable();
            $table->timestamps();
        });

        Schema::create('maintenance_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('device_type');
            $table->string('device_model')->nullable();
            $table->text('issue');
            $table->json('image_urls')->nullable();
            $table->decimal('estimated_cost', 14, 4)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('contact_phone', 40);
            $table->string('status', 24)->default('received');
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject');
            $table->text('message');
            $table->string('status', 24)->default('open');
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('message');
            $table->boolean('is_read')->default(false);
            $table->timestamps();
            $table->index(['is_read', 'created_at']);
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('customer_name');
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->boolean('is_approved')->default(false);
            $table->timestamps();
            $table->index(['is_approved', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 40)->nullable();
            $table->string('preferred_locale', 5)->default('ar');
            $table->json('staff_sections')->nullable();
        });

        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            $table->json('site_name')->nullable();
            $table->json('address')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('whatsapp', 40)->nullable();
            $table->string('facebook_url')->nullable();
            $table->string('instagram_url')->nullable();
            $table->unsignedSmallInteger('max_installments')->default(1);
            $table->json('delivery_info')->nullable();
            $table->json('return_policy')->nullable();
            $table->timestamps();
        });

        Schema::create('site_themes', function (Blueprint $table) {
            $table->id();
            $table->json('theme');
            $table->timestamps();
        });

        Schema::create('store_admin_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('section', 60);
            $table->string('action', 40);
            $table->unsignedBigInteger('record_id')->nullable();
            $table->json('changes')->nullable();
            $table->timestamp('created_at');
            $table->index(['section', 'created_at']);
        });

        DB::table('site_settings')->insert([
            'site_name' => json_encode(['ar' => 'متجري', 'en' => 'My Store', 'he' => 'החנות שלי']),
            'max_installments' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('store_admin_activities');
        Schema::dropIfExists('site_themes');
        Schema::dropIfExists('site_settings');

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('brand_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'preferred_locale', 'staff_sections']);
        });

        Schema::dropIfExists('reviews');
        Schema::dropIfExists('contact_messages');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('maintenance_requests');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('services');
        Schema::dropIfExists('sim_plans');
        Schema::dropIfExists('offers');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('pages');
        Schema::dropIfExists('hero_slides');
        Schema::dropIfExists('brands');
    }
};
