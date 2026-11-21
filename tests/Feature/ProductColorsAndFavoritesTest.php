<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductColor;
use App\Models\User;
use App\Support\WhatsAppOrderMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductColorsAndFavoritesTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->create();
    }

    private function userWithRole(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function product(array $attributes = []): Product
    {
        $product = Product::create([
            'price' => '100.0000',
            'currency' => 'ILS',
            'stock_quantity' => 10,
            'is_active' => true,
            'is_featured' => false,
            ...$attributes,
        ]);

        foreach (['ar' => 'هاتف', 'en' => 'Phone', 'he' => 'טלפון'] as $locale => $name) {
            $product->translations()->create(['locale' => $locale, 'name' => $name, 'slug' => "phone-{$product->id}-{$locale}"]);
        }

        return $product;
    }

    private function color(Product $product, string $arabic, string $hex, int $position = 0): ProductColor
    {
        return $product->colors()->create(['name' => ['ar' => $arabic, 'en' => $arabic, 'he' => $arabic], 'hex' => $hex, 'position' => $position]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return [
            'price' => '100.0000',
            'currency' => 'ILS',
            'is_active' => true,
            'is_featured' => false,
            'price_is_estimate' => false,
            'translations' => [
                'ar' => ['name' => 'هاتف'],
                'en' => ['name' => 'Phone'],
                'he' => ['name' => 'טלפון'],
            ],
            ...$overrides,
        ];
    }

    private function addToCart(Product $product, int $quantity = 1, ?int $colorId = null)
    {
        return $this->actingAs($this->customer)->post(route('cart.items.store'), [
            'product_id' => $product->id,
            'quantity' => $quantity,
            'color_id' => $colorId,
        ]);
    }

    public function test_the_admin_sets_colors_in_order_and_blank_names_use_the_arabic_one(): void
    {
        $manager = $this->userWithRole(UserRole::ProductManager);

        $this->actingAs($manager)->post('/admin/products', $this->form([
            'sync_colors' => true,
            'colors' => [
                ['hex' => '#111111', 'name' => ['ar' => 'أسود', 'en' => 'Black', 'he' => 'שחור']],
                ['hex' => '#ffffff', 'name' => ['ar' => 'أبيض', 'en' => '', 'he' => '']],
            ],
        ]))->assertSessionHasNoErrors();

        $product = Product::query()->sole();
        $colors = $product->colors()->get();

        $this->assertSame(['#111111', '#FFFFFF'], $colors->pluck('hex')->all());
        $this->assertSame(['ar' => 'أبيض', 'en' => 'أبيض', 'he' => 'أبيض'], $colors[1]->name);
        $this->assertSame('Black', $colors[0]->nameIn('en'));
    }

    public function test_editing_keeps_color_ids_reorders_and_removes(): void
    {
        $manager = $this->userWithRole(UserRole::ProductManager);
        $product = $this->product();
        $black = $this->color($product, 'أسود', '#111111', 0);
        $white = $this->color($product, 'أبيض', '#FFFFFF', 1);
        $blue = $this->color($product, 'أزرق', '#2563EB', 2);

        $this->actingAs($manager)->post("/admin/products/{$product->id}", $this->form([
            '_method' => 'put',
            'sync_colors' => true,
            'colors' => [
                ['id' => $blue->id, 'hex' => '#2563EB', 'name' => ['ar' => 'أزرق فاتح']],
                ['id' => $black->id, 'hex' => '#111111', 'name' => ['ar' => 'أسود']],
            ],
        ]))->assertSessionHasNoErrors();

        $this->assertSame([$blue->id, $black->id], $product->colors()->pluck('id')->all());
        $this->assertSame('أزرق فاتح', $blue->fresh()->nameIn('ar'));
        $this->assertNull(ProductColor::find($white->id));

        // Without sync_colors the list is left alone.
        $this->actingAs($manager)->post("/admin/products/{$product->id}", $this->form(['_method' => 'put']))->assertSessionHasNoErrors();
        $this->assertCount(2, $product->colors()->get());
    }

    public function test_bad_colors_are_refused(): void
    {
        $this->actingAs($this->userWithRole(UserRole::ProductManager))
            ->post('/admin/products', $this->form([
                'sync_colors' => true,
                'colors' => [
                    ['hex' => 'red', 'name' => ['ar' => 'أحمر']],
                    ['hex' => '#000000', 'name' => ['ar' => '']],
                    ['hex' => '#000000;background:url(x)', 'name' => ['ar' => 'x']],
                ],
            ]))
            ->assertSessionHasErrors(['colors.0.hex', 'colors.1.name.ar', 'colors.2.hex']);

        $this->assertDatabaseCount('products', 0);
    }

    public function test_cards_and_the_product_page_list_the_colors_in_the_visitors_language(): void
    {
        $product = $this->product();
        $product->colors()->create(['name' => ['ar' => 'أسود', 'en' => 'Black', 'he' => 'שחור'], 'hex' => '#111111']);

        $this->get('/en/products')->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.colors.0.name', 'Black')
            ->where('products.data.0.colors.0.hex', '#111111')
            ->where('products.data.0.is_new', true));

        $this->get("/he/products/phone-{$product->id}-he")->assertInertia(fn (Assert $page) => $page
            ->where('product.colors.0.name', 'שחור'));
    }

    public function test_a_product_with_colors_needs_one_of_its_own(): void
    {
        $product = $this->product();
        $this->color($product, 'أسود', '#111111');
        $other = $this->product();
        $foreign = $this->color($other, 'أحمر', '#DC2626');

        $this->addToCart($product)->assertSessionHasErrors('color_id');
        $this->addToCart($product, 1, $foreign->id)->assertSessionHasErrors('color_id');

        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_a_product_without_colors_ignores_a_color(): void
    {
        $product = $this->product();
        $other = $this->product();
        $foreign = $this->color($other, 'أحمر', '#DC2626');

        $this->addToCart($product, 1, $foreign->id)->assertSessionHasNoErrors();

        $this->assertDatabaseHas('cart_items', ['product_id' => $product->id, 'color_id' => null]);
    }

    public function test_each_color_is_its_own_line_and_they_share_the_stock(): void
    {
        $product = $this->product(['stock_quantity' => 3]);
        $black = $this->color($product, 'أسود', '#111111');
        $white = $this->color($product, 'أبيض', '#FFFFFF');

        $this->addToCart($product, 2, $black->id);
        $this->addToCart($product, 2, $white->id);

        $this->assertDatabaseHas('cart_items', ['color_id' => $black->id, 'quantity' => 2]);
        // Only one unit was left for the second color.
        $this->assertDatabaseHas('cart_items', ['color_id' => $white->id, 'quantity' => 1]);

        $this->actingAs($this->customer)->get('/ar/cart')->assertInertia(fn (Assert $page) => $page
            ->has('cart.items', 2)
            ->where('cart.items.0.color.name', 'أسود')
            ->where('cart.items.0.line_key', "{$product->id}-{$black->id}"));

        // Changing and removing name the color.
        $this->actingAs($this->customer)->patch("/cart/items/{$product->id}?color={$black->id}", ['quantity' => 1]);
        $this->assertDatabaseHas('cart_items', ['color_id' => $black->id, 'quantity' => 1]);
        $this->assertDatabaseHas('cart_items', ['color_id' => $white->id, 'quantity' => 1]);

        $this->actingAs($this->customer)->delete("/cart/items/{$product->id}?color={$white->id}");
        $this->assertDatabaseMissing('cart_items', ['color_id' => $white->id]);
        $this->assertDatabaseHas('cart_items', ['color_id' => $black->id]);
    }

    public function test_the_order_keeps_the_color_and_shows_it_everywhere(): void
    {
        $product = $this->product(['stock_quantity' => 5]);
        $black = $product->colors()->create(['name' => ['ar' => 'أسود', 'en' => 'Black', 'he' => 'שחור'], 'hex' => '#111111']);
        $white = $this->color($product, 'أبيض', '#FFFFFF');
        $this->addToCart($product, 2, $black->id);
        $this->addToCart($product, 1, $white->id);

        $this->actingAs($this->customer)->post(route('checkout.store'), ['customer_name' => 'Sara', 'phone' => '0599123123'])
            ->assertSessionHasNoErrors();

        $order = Order::query()->sole();
        $this->assertSame(['أسود', 'أبيض'], $order->items()->orderBy('id')->pluck('color_name')->all());
        $this->assertSame(2, $product->fresh()->stock_quantity);
        $this->assertStringContainsString(rawurlencode('هاتف (أسود)'), WhatsAppOrderMessage::url($order));

        $this->actingAs($this->customer)->get("/ar/orders/{$order->reference}")
            ->assertInertia(fn (Assert $page) => $page->where('order.items.0.color', 'أسود'));
    }

    public function test_checkout_refuses_color_lines_that_together_exceed_the_stock(): void
    {
        $product = $this->product(['stock_quantity' => 3]);
        $black = $this->color($product, 'أسود', '#111111');
        $white = $this->color($product, 'أبيض', '#FFFFFF');
        $cart = Cart::query()->create(['user_id' => $this->customer->id]);
        $cart->items()->create(['product_id' => $product->id, 'color_id' => $black->id, 'quantity' => 2]);
        $cart->items()->create(['product_id' => $product->id, 'color_id' => $white->id, 'quantity' => 2]);

        $this->actingAs($this->customer)->post(route('checkout.store'), ['customer_name' => 'Sara', 'phone' => '0599123124'])
            ->assertSessionHasErrors('cart');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(3, $product->fresh()->stock_quantity);
    }

    public function test_removing_a_color_drops_it_from_carts(): void
    {
        $product = $this->product();
        $black = $this->color($product, 'أسود', '#111111');
        $this->addToCart($product, 1, $black->id);

        $black->delete();

        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_favorites_return_cards_for_sale_in_the_saved_order(): void
    {
        $first = $this->product();
        $second = $this->product();
        $hidden = $this->product(['is_active' => false]);

        $this->getJson("/ar/favorites/products?ids={$second->id},abc,{$hidden->id},{$first->id},-4,{$second->id}")
            ->assertOk()
            ->assertJsonCount(2, 'products')
            ->assertJsonPath('products.0.id', $second->id)
            ->assertJsonPath('products.1.id', $first->id);

        $this->getJson('/ar/favorites/products')->assertOk()->assertJsonCount(0, 'products');
    }

    public function test_the_favorites_page_is_kept_out_of_search_engines(): void
    {
        $html = (string) $this->get('/ar/favorites')->assertOk()->getContent();

        $this->assertStringContainsString('<meta name="robots" content="noindex" data-seo>', $html);
    }

    public function test_the_admin_product_list_filters_by_visibility_and_stock(): void
    {
        $this->product(['stock_quantity' => 10]);
        $this->product(['stock_quantity' => 2]);
        $this->product(['stock_quantity' => 0]);
        $this->product(['stock_quantity' => 0, 'price_is_estimate' => true]);
        $this->product(['stock_quantity' => 5, 'is_active' => false]);
        $manager = $this->userWithRole(UserRole::ProductManager);

        $this->actingAs($manager)->get('/admin/products')->assertInertia(fn (Assert $page) => $page
            ->where('status', 'all')
            ->where('counts', ['all' => 5, 'shown' => 4, 'hidden' => 1, 'low' => 1, 'out' => 1, 'estimated' => 1, 'on_sale' => 0, 'featured' => 0])
            ->has('products.data', 5));

        $this->actingAs($manager)->get('/admin/products?status=low')->assertInertia(fn (Assert $page) => $page
            ->where('status', 'low')
            ->has('products.data', 1)
            ->where('products.data.0.stock_quantity', 2));

        $this->actingAs($manager)->get('/admin/products?status=bogus')->assertInertia(fn (Assert $page) => $page->where('status', 'all'));
    }
}
