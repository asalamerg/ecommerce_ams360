<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductFormTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = [], string $english = 'Fantech GS205 Speaker', string $arabic = 'سماعة فانتيك'): array
    {
        return [
            'category_id' => null,
            'brand_id' => null,
            'sku' => null,
            'price' => '120.0000',
            'currency' => 'ILS',
            'is_active' => true,
            'is_featured' => false,
            'price_is_estimate' => false,
            'translations' => [
                'ar' => ['name' => $arabic, 'slug' => ''],
                'en' => ['name' => $english, 'slug' => ''],
                'he' => ['name' => 'רמקול', 'slug' => ''],
            ],
            ...$overrides,
        ];
    }

    public function test_a_new_product_gets_its_quantity_recorded_as_a_stock_movement(): void
    {
        $owner = $this->userWithRole(UserRole::Owner);

        $this->actingAs($owner)
            ->post('/admin/products', $this->payload(['stock_quantity' => 12, 'stock_quantity_was' => 0]))
            ->assertSessionHasNoErrors();

        $product = Product::query()->sole();
        $this->assertSame(12, $product->stock_quantity);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'user_id' => $owner->id,
            'direction' => 'increase',
            'quantity_delta' => 12,
            'stock_before' => 0,
            'stock_after' => 12,
            'reason' => 'Initial stock (product form)',
        ]);
        $this->assertTrue($product->isPurchasable());
    }

    public function test_a_product_named_in_arabic_only_with_no_currency_saves_in_shekels(): void
    {
        $this->actingAs($this->userWithRole(UserRole::Owner))
            ->post('/admin/products', $this->payload([
                'currency' => '',
                'translations' => ['ar' => ['name' => 'سماعة بلوتوث', 'slug' => ''], 'en' => ['name' => '', 'slug' => ''], 'he' => ['name' => '', 'slug' => '']],
            ]))
            ->assertSessionHasNoErrors();

        $product = Product::query()->with('translations')->sole();
        $this->assertSame('ILS', $product->currency);
        $this->assertSame(['سماعة بلوتوث'], $product->translations->pluck('name')->unique()->values()->all());
        $this->assertNotSame('', $product->translations->firstWhere('locale', 'en')?->slug);
    }

    public function test_product_errors_name_fields_as_the_form_does(): void
    {
        $this->actingAs($this->userWithRole(UserRole::Owner))
            ->post('/admin/products', $this->payload(['price' => '']))
            ->assertSessionHasErrors(['price' => __('validation.required', ['attribute' => __('Price')])]);
    }

    public function test_an_empty_quantity_creates_the_product_with_no_stock_and_no_movement(): void
    {
        $this->actingAs($this->userWithRole(UserRole::Administrator))
            ->post('/admin/products', $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertSame(0, Product::query()->sole()->stock_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_only_inventory_roles_can_set_the_quantity(): void
    {
        $manager = $this->userWithRole(UserRole::ProductManager);

        $this->actingAs($manager)
            ->post('/admin/products', $this->payload(['stock_quantity' => 50, 'stock_quantity_was' => 0]))
            ->assertSessionHasNoErrors();

        $product = Product::query()->sole();
        $this->assertSame(0, $product->stock_quantity);
        $this->assertDatabaseCount('stock_movements', 0);

        $this->actingAs($manager)
            ->post("/admin/products/{$product->id}", $this->payload(['_method' => 'put', 'stock_quantity' => 50, 'stock_quantity_was' => 0]))
            ->assertSessionHasNoErrors();
        $this->assertSame(0, $product->fresh()->stock_quantity);
    }

    public function test_editing_the_quantity_records_the_difference(): void
    {
        $owner = $this->userWithRole(UserRole::Owner);
        $this->actingAs($owner)->post('/admin/products', $this->payload(['stock_quantity' => 12, 'stock_quantity_was' => 0]));
        $product = Product::query()->sole();

        $this->actingAs($owner)
            ->post("/admin/products/{$product->id}", $this->payload(['_method' => 'put', 'stock_quantity' => 7, 'stock_quantity_was' => 12]))
            ->assertSessionHasNoErrors();

        $this->assertSame(7, $product->fresh()->stock_quantity);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'direction' => 'decrease',
            'quantity_delta' => -5,
            'stock_after' => 7,
            'reason' => 'Set from product form',
        ]);

        // Saving again with the same quantity adds nothing to the log.
        $this->actingAs($owner)->post("/admin/products/{$product->id}", $this->payload(['_method' => 'put', 'stock_quantity' => 7, 'stock_quantity_was' => 7]));
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_a_sale_made_while_editing_is_never_overwritten(): void
    {
        $owner = $this->userWithRole(UserRole::Owner);
        $this->actingAs($owner)->post('/admin/products', $this->payload(['stock_quantity' => 10, 'stock_quantity_was' => 0]));
        $product = Product::query()->sole();

        // An order took 3 units after the form was opened with 10.
        $product->update(['stock_quantity' => 7]);

        $this->actingAs($owner)
            ->post("/admin/products/{$product->id}", $this->payload(['_method' => 'put', 'stock_quantity' => 15, 'stock_quantity_was' => 10]))
            ->assertSessionHasErrors('stock_quantity');

        $this->assertSame(7, $product->fresh()->stock_quantity);
    }

    public function test_empty_slugs_are_made_from_the_english_name_and_kept_unique(): void
    {
        $admin = $this->userWithRole(UserRole::Administrator);

        $this->actingAs($admin)->post('/admin/products', $this->payload())->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/admin/products', $this->payload(['sku' => 'B']))->assertSessionHasNoErrors();

        [$first, $second] = Product::query()->orderBy('id')->get()->all();

        foreach (['ar', 'en', 'he'] as $locale) {
            $this->assertSame('fantech-gs205-speaker', $first->translations()->where('locale', $locale)->value('slug'));
            $this->assertSame('fantech-gs205-speaker-2', $second->translations()->where('locale', $locale)->value('slug'));
        }

        $this->get('/en/products/fantech-gs205-speaker-2')->assertOk();
    }

    public function test_a_typed_slug_is_kept_and_a_cleared_one_does_not_break_old_links(): void
    {
        $admin = $this->userWithRole(UserRole::Administrator);
        $payload = $this->payload();
        $payload['translations']['en']['slug'] = 'my-speaker';

        $this->actingAs($admin)->post('/admin/products', $payload)->assertSessionHasNoErrors();
        $product = Product::query()->sole();
        $this->assertSame('my-speaker', $product->translations()->where('locale', 'en')->value('slug'));

        $this->actingAs($admin)
            ->post("/admin/products/{$product->id}", $this->payload(['_method' => 'put'], english: 'Renamed Speaker'))
            ->assertSessionHasNoErrors();

        $this->assertSame('my-speaker', $product->translations()->where('locale', 'en')->value('slug'));
        $this->assertSame('fantech-gs205-speaker', $product->translations()->where('locale', 'ar')->value('slug'));
    }

    public function test_invalid_typed_slugs_are_still_refused(): void
    {
        $payload = $this->payload();
        $payload['translations']['en']['slug'] = 'Not A Slug!';

        $this->actingAs($this->userWithRole(UserRole::Administrator))
            ->post('/admin/products', $payload)
            ->assertSessionHasErrors('translations.en.slug');
    }

    public function test_categories_get_slugs_from_their_name_too(): void
    {
        $this->actingAs($this->userWithRole(UserRole::ProductManager))
            ->post('/admin/categories', [
                'is_active' => true,
                'position' => 1,
                'translations' => [
                    'ar' => ['name' => 'شواحن سيارة', 'slug' => ''],
                    'en' => ['name' => 'Car Chargers', 'slug' => ''],
                    'he' => ['name' => 'מטענים לרכב', 'slug' => ''],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(['car-chargers'], Category::query()->sole()->translations()->pluck('slug')->unique()->values()->all());
    }

    public function test_a_new_product_is_found_by_search_right_away_in_every_language(): void
    {
        $this->actingAs($this->userWithRole(UserRole::Administrator))->post('/admin/products', $this->payload());

        foreach (['سماعه فانتيك', 'fantech', 'רמקול'] as $query) {
            $this->getJson('/ar/search/suggest?q='.urlencode($query))->assertOk()->assertJsonCount(1, 'products');
        }
    }

    public function test_the_admin_product_list_search_ignores_arabic_spelling_variants_and_finds_hidden_products(): void
    {
        $admin = $this->userWithRole(UserRole::Administrator);
        $this->actingAs($admin)->post('/admin/products', $this->payload(['is_active' => false]));
        $this->actingAs($admin)->post('/admin/products', $this->payload(['sku' => 'CHG-9'], english: 'Car Charger', arabic: 'شاحن سيارة'));

        foreach (['سماعه' => 1, 'سماعة' => 1, 'fantech' => 1, 'CHG-9' => 1, 'شاحن' => 1, 'غير موجود' => 0] as $query => $count) {
            $this->actingAs($admin)->get('/admin/products?q='.urlencode($query))
                ->assertInertia(fn (Assert $page) => $page->has('products.data', $count));
        }
    }
}
