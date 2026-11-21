<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StoreAdminModulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_manage_multilingual_brands(): void
    {
        $admin = $this->userWithRole(UserRole::Administrator);

        $this->actingAs($admin)
            ->post('/admin/modules/brands', [
                'slug' => 'fantech',
                'name_ar' => 'فانتيك',
                'name_en' => 'Fantech',
                'name_he' => 'Fantech',
                'description_ar' => '',
                'description_en' => '',
                'description_he' => '',
                'logo_url' => '',
                'sort_order' => 1,
                'is_active' => true,
            ])
            ->assertRedirect();

        $brand = DB::table('brands')->where('slug', 'fantech')->firstOrFail();
        $this->assertSame(
            ['ar' => 'فانتيك', 'en' => 'Fantech', 'he' => 'Fantech'],
            json_decode($brand->name, true, flags: JSON_THROW_ON_ERROR),
        );
        $this->assertTrue((bool) $brand->is_active);
        $this->assertDatabaseHas('store_admin_activities', [
            'user_id' => $admin->id,
            'section' => 'brands',
            'action' => 'created',
        ]);
    }

    public function test_content_manager_can_view_admin_modules_but_not_orders(): void
    {
        $manager = $this->userWithRole(UserRole::ProductManager);

        $this->actingAs($manager)
            ->get('/admin/modules/brands')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/module/index')
                ->where('module.title', 'العلامات التجارية'),
            );

        $this->get('/admin/orders')->assertForbidden();
    }

    public function test_admin_hero_slides_are_used_on_the_public_homepage(): void
    {
        $admin = $this->userWithRole(UserRole::Administrator);

        $this->actingAs($admin)
            ->post('/admin/modules/hero-slides', [
                'image_url' => 'https://example.test/hero.webp',
                'title_ar' => 'عروض المتجر',
                'title_en' => 'Store offers',
                'title_he' => 'מבצעים',
                'subtitle_ar' => '',
                'subtitle_en' => '',
                'subtitle_he' => '',
                'link_url' => '',
                'sort_order' => 1,
                'is_active' => true,
            ])
            ->assertRedirect();

        $this->get('/ar')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('storefront/home')
                ->where('slides.0.title', 'عروض المتجر')
                ->where('slides.0.image_url', 'https://example.test/hero.webp'),
            );
    }

    public function test_order_manager_can_update_order_status_but_not_financial_totals(): void
    {
        $manager = $this->userWithRole(UserRole::OrderManager);
        $orderId = DB::table('orders')->insertGetId([
            'customer_name' => 'Test Customer',
            'phone' => '0500000000',
            'subtotal' => 100,
            'discount_total' => 0,
            'total' => 100,
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId,
            'name' => json_encode(['en' => 'Headphones', 'ar' => 'سماعات'], JSON_THROW_ON_ERROR),
            'price' => 100,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($manager)
            ->get("/admin/orders/{$orderId}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/orders/show')
                ->where('order.customer_name', 'Test Customer')
                ->where('order.items.0.name', 'سماعات'),
            );

        $this->actingAs($manager)
            ->put("/admin/orders/{$orderId}", [
                'status' => 'processing',
                'payment_status' => 'paid',
                'notes' => 'Verified by staff',
                'total' => 0,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'status' => 'processing',
            'payment_status' => 'paid',
            'total' => 100,
        ]);
    }

    public function test_admin_cannot_promote_a_user_to_administrator_or_owner(): void
    {
        $admin = $this->userWithRole(UserRole::Administrator);
        $customer = User::factory()->create();

        $this->actingAs($admin)
            ->put("/admin/modules/users/{$customer->id}", [
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => '',
                'preferred_locale' => 'ar',
                'role' => 'administrator',
                'is_active' => true,
            ])
            ->assertForbidden();

        $this->assertSame(UserRole::Customer, $customer->fresh()->role);
    }

    public function test_the_users_list_shows_each_persons_plain_name(): void
    {
        $admin = $this->userWithRole(UserRole::Administrator);
        User::factory()->create(['name' => 'Sara Haddad']);

        $this->actingAs($admin)
            ->get('/admin/modules/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/module/index')
                ->where('records.data', fn ($rows) => collect($rows)
                    ->pluck('summary.name')
                    ->contains('Sara Haddad')),
            );
    }

    public function test_store_dashboard_and_reports_are_available_to_authorized_store_staff(): void
    {
        $manager = $this->userWithRole(UserRole::SupportAgent);

        $this->actingAs($manager)
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/dashboard')
                ->where('metrics.products', 0),
            );

        $this->get('/admin/guide')->assertOk();
        $this->get('/admin/reports')->assertForbidden();
    }

    private function userWithRole(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        return $user;
    }
}
