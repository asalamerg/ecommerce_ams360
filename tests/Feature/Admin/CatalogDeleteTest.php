<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CatalogDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function userWithRole(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    private function category(): Category
    {
        $category = Category::create(['is_active' => true, 'position' => 1]);

        foreach (['ar', 'en', 'he'] as $locale) {
            $category->translations()->create(['locale' => $locale, 'name' => "قسم {$locale}", 'slug' => "cat-{$category->id}-{$locale}"]);
        }

        return $category;
    }

    private function product(?Category $category = null): Product
    {
        $product = Product::create([
            'category_id' => $category?->id,
            'price' => '250.0000',
            'currency' => 'ILS',
            'stock_quantity' => 5,
            'is_active' => true,
            'is_featured' => false,
        ]);

        foreach (['ar' => 'سماعة', 'en' => 'Headset', 'he' => 'אוזניות'] as $locale => $name) {
            $product->translations()->create(['locale' => $locale, 'name' => $name, 'slug' => "headset-{$product->id}-{$locale}"]);
        }

        return $product;
    }

    public function test_one_click_hides_and_shows_a_product_in_the_store(): void
    {
        $manager = $this->userWithRole(UserRole::ProductManager);
        $product = $this->product();
        $url = "/ar/products/headset-{$product->id}-ar";

        $this->actingAs($manager)->patch("/admin/products/{$product->id}/visibility")->assertRedirect();
        $this->assertFalse($product->fresh()->is_active);
        $this->get($url)->assertNotFound();
        $this->assertDatabaseHas('catalog_activities', ['action' => 'product.hidden', 'subject_id' => $product->id]);

        $this->actingAs($manager)->patch("/admin/products/{$product->id}/visibility");
        $this->assertTrue($product->fresh()->is_active);
        $this->get($url)->assertOk();
        $this->assertDatabaseHas('catalog_activities', ['action' => 'product.shown', 'subject_id' => $product->id]);
    }

    public function test_one_click_hides_and_shows_a_category(): void
    {
        $category = $this->category();

        $this->actingAs($this->userWithRole(UserRole::Administrator))->patch("/admin/categories/{$category->id}/visibility");

        $this->assertFalse($category->fresh()->is_active);
        $this->assertDatabaseHas('catalog_activities', ['action' => 'category.hidden', 'subject_id' => $category->id]);
    }

    public function test_deleting_a_product_removes_it_for_good_but_keeps_orders_and_history(): void
    {
        $owner = $this->userWithRole(UserRole::Owner);
        $product = $this->product();
        Storage::disk('public')->put("products/{$product->id}/a.webp", 'image');
        $product->images()->create(['path' => "products/{$product->id}/a.webp", 'position' => 0]);

        $order = Order::create([
            'reference' => 'KEEPME01', 'customer_name' => 'Sara', 'phone' => '0599000000', 'item_count' => 1,
            'subtotal' => '250', 'discount_total' => '0', 'total' => '250', 'status' => 'completed', 'payment_status' => 'paid', 'locale' => 'ar',
        ]);
        $order->items()->create(['product_id' => $product->id, 'name' => ['ar' => 'سماعة'], 'price' => '250', 'quantity' => 1, 'price_is_estimate' => false, 'stock_deducted' => 1]);
        StockMovement::create([
            'product_id' => $product->id, 'user_id' => $owner->id, 'direction' => 'increase', 'quantity_delta' => 5,
            'stock_before' => 0, 'stock_after' => 5, 'reason' => 'Initial', 'created_at' => now(),
        ]);
        Cart::query()->create(['user_id' => $owner->id])->items()->create(['product_id' => $product->id, 'quantity' => 1]);

        $this->actingAs($owner)->delete("/admin/products/{$product->id}")->assertRedirect('/admin/products');

        // Gone from the database, not just hidden.
        $this->assertNull(Product::withTrashed()->find($product->id));
        $this->assertDatabaseMissing('product_translations', ['product_id' => $product->id]);
        $this->assertDatabaseMissing('cart_items', ['product_id' => $product->id]);
        Storage::disk('public')->assertMissing("products/{$product->id}/a.webp");

        // The order still reads the same.
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'product_id' => null, 'price' => '250.0000']);
        $this->assertSame('سماعة', $order->items()->sole()->name['ar']);

        // The stock history keeps the name, and the log keeps what was deleted.
        $this->assertDatabaseHas('stock_movements', ['product_id' => null, 'product_name' => 'سماعة', 'reason' => 'Initial']);
        $activity = DB::table('catalog_activities')->where('action', 'product.deleted')->sole();
        $this->assertSame('Headset', json_decode((string) $activity->changes, true)['before']['translations'][1]['name']);

        $this->actingAs($owner)->get('/admin/inventory')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('movements.data.0.product', 'سماعة (محذوف)'));

        // Cancelling the old order now has no stock to return, and does not fail.
        $staff = $this->userWithRole(UserRole::OrderManager);
        $this->actingAs($staff)->put("/admin/orders/{$order->id}", ['status' => 'cancelled', 'payment_status' => 'refunded'])->assertRedirect();
    }

    public function test_images_shared_with_another_product_are_kept(): void
    {
        $first = $this->product();
        $second = $this->product();
        Storage::disk('public')->put('products/shared.webp', 'image');
        $first->images()->create(['path' => 'products/shared.webp', 'position' => 0]);
        $second->images()->create(['path' => 'products/shared.webp', 'position' => 0]);

        $this->actingAs($this->userWithRole(UserRole::Administrator))->delete("/admin/products/{$first->id}");

        Storage::disk('public')->assertExists('products/shared.webp');
    }

    public function test_a_category_with_products_cannot_be_deleted_and_says_why(): void
    {
        $category = $this->category();
        $this->product($category);
        $this->product($category);

        $this->actingAs($this->userWithRole(UserRole::Administrator))
            ->from('/admin/categories')
            ->delete("/admin/categories/{$category->id}")
            ->assertSessionHasErrors(['category' => 'في هذا القسم 2 منتج. انقلها إلى قسم آخر أو احذفها أولًا.']);

        $this->assertNotNull(Category::find($category->id));
    }

    public function test_an_empty_category_is_deleted_for_good_with_its_uploaded_image_only(): void
    {
        $admin = $this->userWithRole(UserRole::Administrator);
        $uploaded = $this->category();
        $imported = $this->category();
        Storage::disk('public')->put("categories/{$uploaded->id}/x.webp", 'image');
        Storage::disk('public')->put('catalog/categories/y.webp', 'image');
        $uploaded->update(['image_path' => "categories/{$uploaded->id}/x.webp"]);
        $imported->update(['image_path' => 'catalog/categories/y.webp']);

        $this->actingAs($admin)->delete("/admin/categories/{$uploaded->id}")->assertRedirect('/admin/categories');
        $this->actingAs($admin)->delete("/admin/categories/{$imported->id}")->assertRedirect('/admin/categories');

        $this->assertNull(Category::withTrashed()->find($uploaded->id));
        $this->assertDatabaseMissing('category_translations', ['category_id' => $uploaded->id]);
        Storage::disk('public')->assertMissing("categories/{$uploaded->id}/x.webp");
        Storage::disk('public')->assertExists('catalog/categories/y.webp');
        $this->assertDatabaseHas('catalog_activities', ['action' => 'category.deleted', 'subject_id' => $uploaded->id]);
    }

    public function test_only_catalog_managers_can_hide_or_delete(): void
    {
        $product = $this->product();
        $category = $this->category();

        foreach ([UserRole::InventoryManager, UserRole::OrderManager, UserRole::SupportAgent, UserRole::Customer] as $role) {
            $user = $this->userWithRole($role);

            $this->actingAs($user)->patch("/admin/products/{$product->id}/visibility")->assertForbidden();
            $this->actingAs($user)->delete("/admin/products/{$product->id}")->assertForbidden();
            $this->actingAs($user)->patch("/admin/categories/{$category->id}/visibility")->assertForbidden();
            $this->actingAs($user)->delete("/admin/categories/{$category->id}")->assertForbidden();
        }

        $this->assertTrue($product->fresh()->is_active);
        $this->assertNotNull(Category::find($category->id));
    }
}
