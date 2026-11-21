<?php

namespace Tests\Feature\Storefront;

use App\Models\Category;
use App\Models\User;
use App\Rules\TurnstileToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesStoreData;
use Tests\TestCase;

class CatalogRefinementsTest extends TestCase
{
    use CreatesStoreData;
    use RefreshDatabase;

    private function child(Category $parent, string $name): Category
    {
        $child = $this->makeCategory($name);
        $child->update(['parent_id' => $parent->id]);

        return $child;
    }

    public function test_other_spellings_find_a_product_and_its_brand(): void
    {
        $brand = DB::table('brands')->insertGetId(['slug' => 'baseus', 'name' => json_encode(['ar' => 'بيسوس', 'en' => 'Baseus']), 'sort_order' => 0, 'is_active' => true]);
        $mouse = $this->makeProduct(['brand_id' => $brand], 'Wireless mouse');
        $phone = $this->makeProduct(['aliases' => 'ايفون، آيفون, iphone'], 'Smart phone');

        $this->actingAs($this->staff())->put("/admin/modules/brands/{$brand}", [
            'slug' => 'baseus', 'name_ar' => 'بيسوس', 'name_en' => 'Baseus', 'name_he' => 'Baseus',
            'aliases' => 'باسيوس', 'sort_order' => 0, 'is_active' => true,
        ])->assertSessionHasNoErrors();

        $this->get('/ar/products?search='.urlencode('باسيوس'))->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 1)->where('products.data.0.id', $mouse->id));
        $this->get('/ar/products?search='.urlencode('ايفون'))->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 1)->where('products.data.0.id', $phone->id));
        $this->getJson('/ar/brands/menu')->assertJsonPath('brands.0.keywords', fn (string $keywords) => str_contains($keywords, 'باسيوس'));
    }

    public function test_a_main_section_lists_its_categories_products_and_offers_them_as_chips(): void
    {
        $section = $this->makeCategory('Chargers');
        $car = $this->child($section, 'Car chargers');
        $wall = $this->child($section, 'Wall chargers');
        $first = $this->makeProduct(['category_id' => $car->id]);
        $second = $this->makeProduct(['category_id' => $wall->id]);
        $this->makeProduct(['category_id' => $this->makeCategory('Other')->id]);

        $slug = $section->translations()->where('locale', 'en')->value('slug');

        $this->get("/en/categories/{$slug}")->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 2)
            ->where('products.data', fn ($items) => collect($items)->pluck('id')->sort()->values()->all() === collect([$first->id, $second->id])->sort()->values()->all())
            ->has('subcategories', 2)
            ->where('parentCategory', null));

        $childSlug = $car->translations()->where('locale', 'en')->value('slug');
        $this->get("/en/categories/{$childSlug}")->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 1)
            ->where('parentCategory.slug', $slug)
            ->where('seo.schema.@graph.0.itemListElement', fn ($crumbs) => collect($crumbs)->pluck('name')->contains('Chargers en')));

        // Home and the categories page show main sections, counting their children.
        $this->get('/en/categories')->assertInertia(fn (Assert $page) => $page
            ->where('categories', fn ($cards) => collect($cards)->firstWhere('slug', $slug)['count'] === 2
                && collect($cards)->pluck('slug')->doesntContain($childSlug)));

        $this->get('/sitemap.xml')->assertSee(url("/en/categories/{$slug}"), false);
    }

    public function test_only_two_levels_of_categories(): void
    {
        $section = $this->makeCategory('Chargers');
        $child = $this->child($section, 'Car chargers');
        $form = fn (Category $category): array => [
            'is_active' => true, 'position' => 0,
            'translations' => collect(['ar', 'en', 'he'])->mapWithKeys(fn (string $locale): array => [$locale => [
                'name' => $category->translations()->where('locale', $locale)->value('name'),
                'slug' => $category->translations()->where('locale', $locale)->value('slug'),
            ]])->all(),
        ];
        $admin = $this->staff();

        $this->actingAs($admin)->put("/admin/categories/{$section->id}", [...$form($section), 'parent_id' => $this->makeCategory('Other')->id])
            ->assertSessionHasErrors('parent_id');
        $this->actingAs($admin)->put("/admin/categories/{$this->makeCategory('Third')->id}", [...$form($child), 'parent_id' => $child->id])
            ->assertSessionHasErrors('parent_id');
    }

    public function test_category_circles_use_a_small_copy(): void
    {
        Storage::fake('public');
        $image = imagecreatetruecolor(1600, 900);
        ob_start();
        imagewebp($image);
        Storage::disk('public')->put('catalog/categories/cables.webp', (string) ob_get_clean());
        $category = $this->makeCategory('Cables');
        $category->update(['image_path' => 'catalog/categories/cables.webp']);
        $this->makeProduct(['category_id' => $category->id]);

        $this->artisan('store:make-thumbnails')->assertSuccessful();

        $this->assertSame('catalog/categories/cables.w320.webp', $category->fresh()->thumb_path);
        $this->get('/en/categories')->assertInertia(fn (Assert $page) => $page
            ->where('categories.0.image_url', '/storage/catalog/categories/cables.w320.webp'));
    }

    public function test_the_grouping_command_previews_then_groups_and_can_run_again(): void
    {
        foreach (['Yesido Car Chargers', 'KLGO Car Phone Holders', 'JBL Bluetooth Speakers'] as $name) {
            $category = Category::create(['is_active' => true, 'position' => 1]);
            foreach (['ar', 'en', 'he'] as $locale) {
                $category->translations()->create(['locale' => $locale, 'name' => $name, 'slug' => str($name)->slug().($locale === 'en' ? '' : "-{$locale}")]);
            }
        }

        $this->artisan('store:group-categories --dry-run')->assertSuccessful();
        $this->assertSame(0, Category::query()->whereNotNull('parent_id')->count());

        $this->artisan('store:group-categories')->assertSuccessful();
        $this->artisan('store:group-categories')->assertSuccessful();

        $car = DB::table('category_translations')->where('locale', 'en')->where('slug', 'car-accessories')->value('category_id');
        $this->assertSame(2, Category::query()->where('parent_id', $car)->count());
        $this->assertSame(3, Category::query()->whereNotNull('parent_id')->count());
        $this->assertSame(1, DB::table('category_translations')->where('locale', 'en')->where('slug', 'car-accessories')->count());
    }

    public function test_back_in_stock_and_order_tracking_need_the_bot_check_when_it_is_on(): void
    {
        config(['services.turnstile.site_key' => 'site', 'services.turnstile.secret_key' => 'secret']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);
        $product = $this->makeProduct(['stock_quantity' => 0, 'price_is_estimate' => false]);

        $this->assertTrue(TurnstileToken::enabled());
        $this->post("/en/products/{$product->id}/notify", ['email' => 'a@example.com'])->assertSessionHasErrors('turnstile_token');
        $this->post("/en/products/{$product->id}/notify", ['email' => 'a@example.com', 'turnstile_token' => 'fake'])->assertSessionHasErrors('turnstile_token');
        $this->post('/en/track', ['reference' => 'ABCD1234', 'phone' => '0501234567'])->assertSessionHasErrors('turnstile_token');
        $this->assertDatabaseCount('stock_alerts', 0);

        $this->get('/en/track')->assertInertia(fn (Assert $page) => $page->where('turnstileSiteKey', 'site'));
    }

    public function test_each_rate_limit_counts_on_its_own(): void
    {
        $product = $this->makeProduct(['stock_quantity' => 0, 'price_is_estimate' => false]);

        // Returning from payment pages and sending CSP reports used to share one
        // counter per visitor with these forms.
        foreach (range(1, 6) as $attempt) {
            $this->postJson('/csp-report', ['csp-report' => []]);
            $this->post('/en/track', ['reference' => "NOPE{$attempt}", 'phone' => '0501234567']);
        }

        $this->post('/en/track', ['reference' => 'NOPE7', 'phone' => '0501234567'])->assertStatus(429);
        $this->post("/en/products/{$product->id}/notify", ['email' => 'still@example.com'])->assertSessionHasNoErrors()->assertRedirect();
    }

    public function test_app_icons_are_drawn_in_both_sizes(): void
    {
        foreach (['/icons/app-192.png', '/icons/app-512.png', '/icons/app-512-maskable.png'] as $url) {
            $png = $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')->getContent();
            [$width] = getimagesizefromstring($png);
            $this->assertSame(str_contains($url, '192') ? 192 : 512, $width);
        }

        $this->get('/icons/app-100.png')->assertNotFound();
        $this->get('/manifest.webmanifest')->assertJsonPath('icons.1.purpose', 'maskable');
    }

    public function test_compare_returns_up_to_four_products_for_sale_in_the_order_picked(): void
    {
        $products = collect(range(1, 5))->map(fn () => $this->makeProduct());
        $hidden = $this->makeProduct(['is_active' => false]);
        $ids = [$products[2]->id, $hidden->id, $products[0]->id, $products[1]->id, $products[3]->id, $products[4]->id];

        $this->get('/en/compare')->assertOk();
        $this->getJson('/en/compare/products?ids='.implode(',', $ids))
            ->assertOk()
            ->assertJsonCount(3, 'products')
            ->assertJsonPath('products.0.id', $products[2]->id)
            ->assertJsonPath('products.1.id', $products[0]->id)
            ->assertJsonStructure(['products' => [['id', 'price', 'rating' => ['average', 'count'], 'sku', 'summary']]]);
    }

    public function test_settings_screens_receive_the_translations(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['preferred_locale' => 'ar'])->save();

        $this->actingAs($user)->get('/settings/profile')->assertInertia(fn (Assert $page) => $page
            ->where('adminText.ar.Profile settings', 'إعدادات الملف الشخصي'));
    }
}
