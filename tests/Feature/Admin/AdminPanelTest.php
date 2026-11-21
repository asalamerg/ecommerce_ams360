<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserRole $role = UserRole::Administrator): User
    {
        return tap(User::factory()->create(), fn (User $user) => $user->forceFill(['role' => $role])->save());
    }

    /**
     * @return list<string>
     */
    private function navLinks(User $user): array
    {
        $links = [];

        $this->actingAs($user)->get('/admin/dashboard')->assertInertia(function (Assert $page) use (&$links) {
            $page->where('admin.groups', function ($groups) use (&$links) {
                foreach ($groups as $group) {
                    foreach ($group['items'] as $item) {
                        $links[] = $item['href'];
                    }
                }

                return true;
            });
        });

        return $links;
    }

    public function test_the_panel_is_arabic_and_right_to_left_by_default(): void
    {
        $this->actingAs($this->user())->get('/admin/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'ar')
            ->where('direction', 'rtl')
            ->where('adminText.ar.Products', 'المنتجات')
            ->where('admin.groups.0.items.0.label', 'الرئيسية'));
    }

    public function test_an_admin_can_switch_the_panel_language(): void
    {
        $admin = $this->user();

        $this->actingAs($admin)->get('/admin/dashboard?lang=en')->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'en')
            ->where('direction', 'ltr')
            ->where('admin.groups.0.items.0.label', 'Dashboard'));

        $this->get('/admin/products')->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));

        $this->get('/admin/dashboard?lang=he')->assertInertia(fn (Assert $page) => $page
            ->where('direction', 'rtl')
            ->where('adminText.he.Products', 'מוצרים'));
    }

    public function test_browsing_the_store_in_another_language_leaves_the_panel_language_alone(): void
    {
        $this->actingAs($this->user())->get('/admin/dashboard')->assertInertia(fn (Assert $page) => $page->where('locale', 'ar'));

        $this->get('/en')->assertOk();
        $this->get('/admin/dashboard')->assertInertia(fn (Assert $page) => $page->where('locale', 'ar'));

        // And the store keeps its own: back on a page without a language in the link.
        $this->get('/admin/dashboard?lang=he');
        $this->get('/en')->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
    }

    public function test_module_labels_and_messages_are_translated(): void
    {
        $admin = $this->user();

        $this->actingAs($admin)->get('/admin/modules/coupons')->assertInertia(fn (Assert $page) => $page
            ->where('module.title', 'القسائم')
            ->where('module.columns.code', 'الرمز'));
    }

    public function test_guests_and_customers_receive_no_admin_shell(): void
    {
        $this->get('/ar')->assertInertia(fn (Assert $page) => $page->where('admin', null)->where('adminText', []));

        $this->actingAs(User::factory()->create())
            ->get('/ar')
            ->assertInertia(fn (Assert $page) => $page->where('admin', null));
    }

    public function test_every_link_in_each_roles_menu_opens(): void
    {
        foreach (UserRole::cases() as $role) {
            if (! $role->isStaff()) {
                continue;
            }

            $user = $this->user($role);

            foreach ($this->navLinks($user) as $link) {
                $this->actingAs($user)->get($link)->assertOk();
            }
        }
    }

    public function test_menus_are_limited_to_what_the_role_can_do(): void
    {
        $inventory = $this->navLinks($this->user(UserRole::InventoryManager));
        $support = $this->navLinks($this->user(UserRole::SupportAgent));
        $admin = $this->navLinks($this->user());

        $this->assertContains('/admin/inventory', $inventory);
        $this->assertNotContains('/admin/modules/users', $inventory);
        $this->assertNotContains('/admin/products', $inventory);

        $this->assertContains('/admin/modules/support', $support);
        $this->assertNotContains('/admin/theme', $support);

        $this->assertContains('/admin/theme', $admin);
        $this->assertContains('/admin/modules/users', $admin);
    }

    public function test_alerts_count_real_work_for_the_role(): void
    {
        DB::table('contact_messages')->insert(['name' => 'Sara', 'message' => 'Hello', 'is_read' => false, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->user(UserRole::SupportAgent))->get('/admin/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('admin.alerts.0.key', 'contact')
            ->where('admin.alerts.0.count', 1));

        $this->actingAs($this->user(UserRole::InventoryManager))->get('/admin/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('admin.alerts', []));
    }

    public function test_sales_figures_are_hidden_from_roles_without_order_access(): void
    {
        $this->actingAs($this->user(UserRole::SupportAgent))->get('/admin/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('sales', null)
            ->where('recentOrders', []));
    }

    public function test_dashboard_sales_exclude_cancelled_orders(): void
    {
        $order = fn (string $status, int $total) => DB::table('orders')->insert([
            'customer_name' => 'Client', 'phone' => '050', 'total' => $total, 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $order('pending', 100);
        $order('completed', 50);
        $order('cancelled', 900);

        $this->actingAs($this->user())->get('/admin/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('sales.today', '150')
            ->where('sales.today_orders', 2)
            ->has('sales.days', 14)
            ->has('recentOrders', 3));
    }

    public function test_the_product_list_can_be_searched(): void
    {
        foreach (['FIND-ME', 'OTHER-1'] as $sku) {
            Product::create(['sku' => $sku, 'price' => '10.0000', 'currency' => 'ILS', 'stock_quantity' => 1, 'is_active' => true, 'is_featured' => false]);
        }

        $this->actingAs($this->user())->get('/admin/products?q=FIND')->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.sku', 'FIND-ME')
            ->where('search', 'FIND'));
    }

    public function test_module_search_matches_only_its_columns_and_treats_wildcards_literally(): void
    {
        $admin = $this->user();
        User::factory()->create(['email' => 'sara@example.com']);

        $this->actingAs($admin)->get('/admin/modules/users?q=sara')->assertInertia(fn (Assert $page) => $page->has('records.data', 1));
        $this->get('/admin/modules/users?q=%25')->assertInertia(fn (Assert $page) => $page->has('records.data', 0));
    }
}
