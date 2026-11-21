<?php

namespace Tests\Feature\Storefront;

use App\Actions\Inventory\AdjustProductStock;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Notifications\BackInStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesStoreData;
use Tests\TestCase;

class CustomerFeaturesTest extends TestCase
{
    use CreatesStoreData;
    use RefreshDatabase;

    private function completedOrder(User $user, Product $product, int $quantity = 1, string $status = 'completed'): Order
    {
        $order = Order::query()->create([
            'reference' => Str::upper(Str::random(8)), 'user_id' => $user->id, 'customer_name' => 'Sara Haddad', 'phone' => '0501234567',
            'shipping_address' => ['address' => 'Herzl 5, Haifa'], 'item_count' => $quantity, 'subtotal' => '100', 'discount_total' => '0',
            'total' => '100', 'status' => $status, 'payment_method' => 'whatsapp', 'payment_status' => 'paid', 'locale' => 'en',
        ]);
        $order->items()->create(['product_id' => $product->id, 'name' => ['en' => 'Headset'], 'price' => '100', 'quantity' => $quantity]);

        return $order;
    }

    private function slug(Product $product): string
    {
        return $product->translations->firstWhere('locale', 'en')->slug;
    }

    public function test_only_customers_who_received_the_product_can_review_it(): void
    {
        $product = $this->makeProduct();
        $buyer = User::factory()->create(['name' => 'Sara Haddad']);
        $this->completedOrder($buyer, $product);
        $pendingBuyer = User::factory()->create();
        $this->completedOrder($pendingBuyer, $product, status: 'processing');

        $this->post("/en/products/{$product->id}/reviews", ['rating' => 5])->assertRedirect(route('login'));
        $this->actingAs($pendingBuyer)->post("/en/products/{$product->id}/reviews", ['rating' => 1])->assertForbidden();
        $this->actingAs(User::factory()->create())->post("/en/products/{$product->id}/reviews", ['rating' => 1])->assertForbidden();

        $this->actingAs($buyer)->post("/en/products/{$product->id}/reviews", ['rating' => 4, 'comment' => '<b>Great</b> sound'])->assertRedirect();
        $this->actingAs($buyer)->post("/en/products/{$product->id}/reviews", ['rating' => 5, 'comment' => 'Even better'])->assertRedirect();

        $this->assertDatabaseCount('reviews', 1);
        $this->assertDatabaseHas('reviews', ['user_id' => $buyer->id, 'rating' => 5, 'comment' => 'Even better', 'is_approved' => false]);
    }

    public function test_approved_reviews_show_first_names_and_stars_for_search_engines(): void
    {
        $product = $this->makeProduct();
        $buyer = User::factory()->create(['name' => 'Sara Haddad']);
        $this->completedOrder($buyer, $product);
        DB::table('reviews')->insert([
            ['product_id' => $product->id, 'user_id' => $buyer->id, 'customer_name' => 'Sara Haddad', 'rating' => 4, 'comment' => 'Good', 'is_approved' => true, 'created_at' => now()],
            ['product_id' => $product->id, 'user_id' => null, 'customer_name' => 'Hidden', 'rating' => 1, 'comment' => 'Spam', 'is_approved' => false, 'created_at' => now()],
        ]);

        $this->actingAs($buyer)->get("/en/products/{$this->slug($product)}")->assertInertia(fn (Assert $page) => $page
            ->where('reviews.count', 1)
            ->where('reviews.average', 4)
            ->where('reviews.items.0.name', 'Sara')
            ->has('reviews.items', 1)
            ->where('reviews.can_review', true)
            ->where('seo.schema.@graph.0.aggregateRating.reviewCount', 1));
    }

    public function test_back_in_stock_e_mails_go_once_when_stock_returns(): void
    {
        Notification::fake();
        $product = $this->makeProduct(['stock_quantity' => 0, 'price_is_estimate' => false]);

        $this->get("/en/products/{$this->slug($product)}")->assertInertia(fn (Assert $page) => $page->where('notifyWhenAvailable', true));
        $this->post("/en/products/{$product->id}/notify", ['email' => 'Fan@Example.com'])->assertRedirect()->assertSessionHas('success');
        $this->post("/en/products/{$product->id}/notify", ['email' => 'fan@example.com'])->assertRedirect();
        $this->assertDatabaseCount('stock_alerts', 1);

        app(AdjustProductStock::class)->execute($product, $this->staff(), 'increase', 3, 'Delivery');
        app(AdjustProductStock::class)->execute($product->fresh(), $this->staff(), 'increase', 2, 'Delivery');

        Notification::assertSentOnDemandTimes(BackInStock::class, 1);
        Notification::assertSentOnDemand(BackInStock::class, fn (BackInStock $mail, array $channels, AnonymousNotifiable $to): bool => $to->routes['mail'] === 'fan@example.com');
        $this->assertNotNull(DB::table('stock_alerts')->value('notified_at'));
    }

    public function test_order_again_fills_the_cart_with_what_is_still_for_sale(): void
    {
        $user = User::factory()->create();
        $available = $this->makeProduct(['stock_quantity' => 10]);
        $gone = $this->makeProduct(['is_active' => false]);
        $order = $this->completedOrder($user, $available, 2);
        $order->items()->create(['product_id' => $gone->id, 'name' => ['en' => 'Old'], 'price' => '10', 'quantity' => 1]);

        $this->actingAs($user)->post("/en/orders/{$order->reference}/reorder")
            ->assertRedirect('/en/cart')
            ->assertSessionHas('success', __('ui.reorder.partly', ['count' => 1]));

        $cart = Cart::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame([[$available->id, 2]], $cart->items->map(fn ($item) => [$item->product_id, $item->quantity])->all());

        $this->actingAs(User::factory()->create())->post("/en/orders/{$order->reference}/reorder")->assertNotFound();
    }

    public function test_checkout_remembers_the_last_delivery_address(): void
    {
        $user = User::factory()->create();
        $this->completedOrder($user, $this->makeProduct());

        $this->actingAs($user)->get('/en/cart')->assertInertia(fn (Assert $page) => $page->where('customer.address', 'Herzl 5, Haifa'));
    }

    public function test_favorites_merge_between_the_browser_and_the_account(): void
    {
        $user = User::factory()->create();
        [$a, $b, $c] = [$this->makeProduct(), $this->makeProduct(), $this->makeProduct()];
        DB::table('favorites')->insert(['user_id' => $user->id, 'product_id' => $a->id, 'created_at' => now()->subDay()]);

        $ids = $this->actingAs($user)->postJson('/account/favorites/sync', ['ids' => [$b->id, 999999]])->assertOk()->json('ids');
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ids);

        $ids = $this->actingAs($user)->postJson('/account/favorites/sync', ['ids' => [$c->id], 'removed' => [$a->id]])->json('ids');
        $this->assertEqualsCanonicalizing([$b->id, $c->id], $ids);

        auth()->guard('web')->logout();
        $this->postJson('/account/favorites/sync', ['ids' => []])->assertUnauthorized();
    }

    public function test_listing_filters_by_price_brand_and_availability_and_stays_out_of_search(): void
    {
        $brand = DB::table('brands')->insertGetId(['slug' => 'baseus', 'name' => json_encode(['en' => 'Baseus']), 'sort_order' => 0, 'is_active' => true]);
        $cheap = $this->makeProduct(['price' => '20', 'brand_id' => $brand]);
        $this->makeProduct(['price' => '500', 'brand_id' => $brand]);
        $this->makeProduct(['price' => '30', 'stock_quantity' => 0, 'price_is_estimate' => false]);

        $this->get('/en/products?price_max=100&available=1')->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.id', $cheap->id)
            ->where('seo.robots', 'noindex, follow'));

        $this->get('/en/products?brand=baseus')->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 2)
            ->where('brands.0.name', 'Baseus'));

        $this->get('/en/products?brand=<script>')->assertSessionHasErrors('brand');
    }

    public function test_the_web_app_manifest_uses_the_store_name_and_language(): void
    {
        $this->get('/manifest.webmanifest')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJsonPath('display', 'standalone')
            ->assertJsonStructure(['name', 'theme_color', 'icons']);
    }
}
