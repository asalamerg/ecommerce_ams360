<?php

namespace Tests\Feature\Storefront;

use App\Models\Cart;
use App\Models\Product;
use App\Models\User;
use App\Services\CartManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CartTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function product(array $attributes = []): Product
    {
        static $number = 0;
        $number++;

        $product = Product::create([
            'sku' => "SKU-{$number}",
            'price' => '100.0000',
            'currency' => 'ILS',
            'stock_quantity' => 10,
            'is_active' => true,
            'is_featured' => false,
            ...$attributes,
        ]);

        foreach (['ar', 'en', 'he'] as $locale) {
            $product->translations()->create(['locale' => $locale, 'name' => "Product {$number}", 'slug' => "product-{$number}-{$locale}"]);
        }

        return $product;
    }

    private function add(Product $product, int $quantity = 1)
    {
        return $this->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => $quantity]);
    }

    private function guestToken(): string
    {
        return (string) Cart::query()->whereNull('user_id')->value('token');
    }

    public function test_a_signed_in_customer_can_add_change_and_remove_a_line(): void
    {
        $user = User::factory()->create();
        $product = $this->product();

        $this->actingAs($user);
        $this->add($product, 2)->assertRedirect();

        $this->get('/ar/cart')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('storefront/cart')
            ->where('cart.count', 2)
            ->where('cart.subtotal', '200.0000')
            ->has('cart.items', 1));

        $this->patch(route('cart.items.update', $product->id), ['quantity' => 3]);
        $this->assertDatabaseHas('cart_items', ['product_id' => $product->id, 'quantity' => 3]);

        $this->delete(route('cart.items.destroy', $product->id));
        $this->assertDatabaseMissing('cart_items', ['product_id' => $product->id]);
    }

    public function test_a_guest_keeps_a_cart_through_an_encrypted_cookie(): void
    {
        $product = $this->product();

        $this->add($product)->assertCookie(CartManager::COOKIE);

        $this->withCookie(CartManager::COOKIE, $this->guestToken())
            ->get('/ar')
            ->assertInertia(fn (Assert $page) => $page->where('cart.count', 1));
    }

    public function test_adding_the_same_product_again_increases_the_line(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product();

        $this->add($product, 2);
        $this->add($product, 3);

        $this->assertDatabaseCount('cart_items', 1);
        $this->assertDatabaseHas('cart_items', ['product_id' => $product->id, 'quantity' => 5]);
    }

    public function test_a_price_sent_by_the_browser_is_ignored(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product();

        $this->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 1, 'price' => '0.01', 'line_total' => '0.01']);

        $this->get('/ar/cart')->assertInertia(fn (Assert $page) => $page->where('cart.subtotal', '100.0000'));
    }

    public function test_a_price_change_reaches_existing_carts(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product();

        $this->add($product, 2);
        $product->update(['price' => '150.0000']);

        $this->get('/ar/cart')->assertInertia(fn (Assert $page) => $page->where('cart.subtotal', '300.0000'));
    }

    public function test_quantity_cannot_pass_available_stock(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product(['stock_quantity' => 3]);

        $this->add($product, 2);
        $this->add($product, 5);

        $this->assertDatabaseHas('cart_items', ['product_id' => $product->id, 'quantity' => 3]);

        $this->patch(route('cart.items.update', $product->id), ['quantity' => 40]);
        $this->assertDatabaseHas('cart_items', ['product_id' => $product->id, 'quantity' => 3]);
    }

    public function test_sold_out_hidden_and_deleted_products_cannot_be_added(): void
    {
        $this->actingAs(User::factory()->create());

        foreach ([
            $this->product(['stock_quantity' => 0]),
            $this->product(['is_active' => false]),
        ] as $product) {
            $this->add($product)->assertSessionHasErrors('cart');
        }

        $deleted = $this->product();
        $deleted->delete();

        $this->add($deleted)->assertSessionHasErrors('cart');
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_an_estimated_product_without_stock_can_be_added_within_a_small_limit(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product(['stock_quantity' => 0, 'price_is_estimate' => true]);

        $this->add($product, 20);

        $this->assertDatabaseHas('cart_items', ['product_id' => $product->id, 'quantity' => Product::UNCONFIRMED_ORDER_QUANTITY]);
    }

    public function test_invalid_quantities_are_refused(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product();

        foreach ([0, -5, 51, 'abc'] as $quantity) {
            $this->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => $quantity])
                ->assertSessionHasErrors('quantity');
        }

        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_a_line_that_becomes_unavailable_is_flagged_and_leaves_the_total(): void
    {
        $this->actingAs(User::factory()->create());
        $keeps = $this->product();
        $goes = $this->product();

        $this->add($keeps);
        $this->add($goes);
        $goes->update(['is_active' => false]);

        $this->get('/ar/cart')->assertInertia(fn (Assert $page) => $page
            ->where('cart.count', 1)
            ->where('cart.subtotal', '100.0000')
            ->has('cart.items', 2)
            ->where('cart.items.1.available', false));
    }

    public function test_a_guest_cart_merges_into_the_account_on_login(): void
    {
        $user = User::factory()->create();
        $inBoth = $this->product(['stock_quantity' => 4]);
        $guestOnly = $this->product();

        $this->add($inBoth, 3);
        $token = $this->guestToken();
        $this->withCookie(CartManager::COOKIE, $token);
        $this->add($guestOnly, 1);

        $cart = Cart::query()->create(['user_id' => $user->id]);
        $cart->items()->create(['product_id' => $inBoth->id, 'quantity' => 2]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $this->assertDatabaseHas('cart_items', ['cart_id' => $cart->id, 'product_id' => $inBoth->id, 'quantity' => 4]);
        $this->assertDatabaseHas('cart_items', ['cart_id' => $cart->id, 'product_id' => $guestOnly->id, 'quantity' => 1]);
        $this->assertDatabaseMissing('carts', ['token' => $token]);
    }

    public function test_one_customer_cannot_change_another_customers_cart(): void
    {
        $owner = User::factory()->create();
        $product = $this->product();

        $this->actingAs($owner);
        $this->add($product, 2);

        $this->actingAs(User::factory()->create());
        $this->delete(route('cart.items.destroy', $product->id));
        $this->patch(route('cart.items.update', $product->id), ['quantity' => 1]);

        $this->assertDatabaseHas('cart_items', ['product_id' => $product->id, 'quantity' => 2]);
    }

    public function test_cart_requests_are_rate_limited(): void
    {
        $this->actingAs(User::factory()->create());
        $product = $this->product();

        foreach (range(1, 60) as $attempt) {
            $this->delete(route('cart.items.destroy', $product->id))->assertRedirect();
        }

        $this->delete(route('cart.items.destroy', $product->id))->assertTooManyRequests();
    }
}
