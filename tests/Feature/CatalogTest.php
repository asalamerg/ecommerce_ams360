<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\CatalogActivity;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_catalog_admin(): void
    {
        $this->get('/admin/products')->assertRedirect(route('admin.login'));
    }

    public function test_customer_cannot_access_catalog_admin(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/admin/products')
            ->assertForbidden();
    }

    public function test_product_manager_can_render_catalog_management_pages(): void
    {
        $this->actingAs($this->userWithRole(UserRole::ProductManager));

        $this->get('/admin/products')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/products/index'));

        $this->get('/admin/products/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/products/form'));

        $this->get('/admin/categories')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/categories/index'));

        $this->get('/admin/activity')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/activity/index'));
    }

    public function test_product_manager_can_create_a_trilingual_product(): void
    {
        $manager = $this->userWithRole(UserRole::ProductManager);

        $this->actingAs($manager)
            ->post('/admin/products', $this->productData())
            ->assertRedirect();

        $product = Product::query()->with('translations')->firstOrFail();

        $this->assertSame('129.9000', $product->price);
        $this->assertSame(0, $product->stock_quantity);
        $this->assertSame('ILS', $product->currency);
        $this->assertCount(3, $product->translations);
        $this->assertDatabaseHas('product_translations', [
            'product_id' => $product->id,
            'locale' => 'ar',
            'slug' => 'phone-ar',
        ]);
        $this->assertDatabaseHas('catalog_activities', [
            'user_id' => $manager->id,
            'action' => 'product.created',
            'subject_type' => Product::class,
            'subject_id' => $product->id,
        ]);
        $activity = CatalogActivity::query()->where('action', 'product.created')->firstOrFail();
        $this->assertSame('Phone', $activity->changes['after']['translations'][1]['name']);
    }

    public function test_product_manager_can_update_translations_without_slug_conflict(): void
    {
        $manager = $this->userWithRole(UserRole::ProductManager);
        $product = $this->createProduct();
        $data = $this->productData();
        $data['translations']['ar']['name'] = 'هاتف محدّث';

        $this->actingAs($manager)
            ->put("/admin/products/{$product->id}", $data)
            ->assertRedirect();

        $this->assertDatabaseHas('product_translations', [
            'product_id' => $product->id,
            'locale' => 'ar',
            'name' => 'هاتف محدّث',
            'slug' => 'phone-ar',
        ]);
        $this->assertDatabaseHas('catalog_activities', [
            'user_id' => $manager->id,
            'action' => 'product.updated',
            'subject_type' => Product::class,
            'subject_id' => $product->id,
        ]);
        $activity = CatalogActivity::query()->where('action', 'product.updated')->firstOrFail();
        $this->assertSame('هاتف محدّث', $activity->changes['after']['translations'][0]['name']);
    }

    public function test_invalid_price_is_rejected(): void
    {
        $data = $this->productData();
        $data['price'] = '-1';

        $this->actingAs($this->userWithRole(UserRole::Administrator))
            ->from('/admin/products/create')
            ->post('/admin/products', $data)
            ->assertRedirect('/admin/products/create')
            ->assertSessionHasErrors('price');

        $this->assertDatabaseCount('products', 0);
    }

    public function test_public_storefront_shows_active_products_in_requested_language_even_without_stock(): void
    {
        $product = $this->createProduct();
        $hidden = $this->createProduct(['is_active' => false]);
        $outOfStock = $this->createProduct(['stock_quantity' => 0]);

        $this->get('/ar/products')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('storefront/index')
                ->where('products.data.1.name', 'هاتف')
                ->where('products.data.1.slug', 'phone-ar')
                ->where('products.data.1.stock_quantity', 7)
                ->has('products.data', 2),
            );

        $this->assertNotSame($product->id, $hidden->id);
        $this->assertNotSame($product->id, $outOfStock->id);
    }

    public function test_public_product_route_returns_localized_product_and_hides_drafts(): void
    {
        $product = $this->createProduct();
        $draft = $this->createProduct(['is_active' => false]);

        $this->get('/he/products/phone-he')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('storefront/show')
                ->where('product.name', 'טלפון')
                ->where('locale', 'he')
                ->where('direction', 'rtl'),
            );

        $this->get('/en/products/draft-en')->assertNotFound();
        $this->assertNotSame($product->id, $draft->id);
    }

    public function test_admin_can_create_a_category_but_cannot_archive_it_while_products_are_assigned(): void
    {
        $manager = $this->userWithRole(UserRole::Administrator);
        $category = $this->createCategory();
        $product = $this->createProduct(['category_id' => $category->id]);

        $this->actingAs($manager)
            ->delete("/admin/categories/{$category->id}")
            ->assertSessionHasErrors('category');

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_category_manager_can_create_localized_category_translations(): void
    {
        $this->actingAs($this->userWithRole(UserRole::Administrator))
            ->post('/admin/categories', [
                'is_active' => true,
                'position' => 3,
                'translations' => [
                    'ar' => ['name' => 'هواتف', 'slug' => 'phones-ar'],
                    'en' => ['name' => 'Phones', 'slug' => 'phones-en'],
                    'he' => ['name' => 'טלפונים', 'slug' => 'phones-he'],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseCount('category_translations', 3);
        $this->assertSame(1, CatalogActivity::where('action', 'category.created')->count());
    }

    public function test_store_role_command_requires_a_verified_user(): void
    {
        $user = User::factory()->unverified()->create();

        $this->assertSame(1, Artisan::call('store:grant-role', [
            'email' => $user->email,
            'role' => UserRole::Owner->value,
        ]));
        $this->assertSame(UserRole::Customer, $user->fresh()->role);
    }

    public function test_product_update_cannot_change_role_or_stock(): void
    {
        $manager = $this->userWithRole(UserRole::Administrator);
        $product = $this->createProduct();
        $data = $this->productData();
        $data['role'] = UserRole::Owner->value;
        $data['stock_quantity'] = 99;

        $this->actingAs($manager)->put("/admin/products/{$product->id}", $data);

        $this->assertSame(UserRole::Administrator, $manager->fresh()->role);
        $this->assertSame(7, $product->fresh()->stock_quantity);
    }

    private function userWithRole(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function productData(array $overrides = []): array
    {
        return array_replace_recursive([
            'category_id' => null,
            'sku' => null,
            'price' => '129.9000',
            'currency' => 'ILS',
            'stock_quantity' => 7,
            'is_active' => true,
            'is_featured' => true,
            'price_is_estimate' => false,
            'translations' => [
                'ar' => ['name' => 'هاتف', 'slug' => 'phone-ar', 'description' => 'وصف'],
                'en' => ['name' => 'Phone', 'slug' => 'phone-en', 'description' => 'Description'],
                'he' => ['name' => 'טלפון', 'slug' => 'phone-he', 'description' => 'תיאור'],
            ],
        ], $overrides);
    }

    private function createProduct(array $overrides = []): Product
    {
        $data = $this->productData($overrides);
        $slugSuffix = Product::withTrashed()->count() > 0
            ? '-'.(Product::withTrashed()->count() + 1)
            : '';
        $product = Product::create([
            'category_id' => $data['category_id'],
            'sku' => $data['sku'],
            'price' => $data['price'],
            'currency' => $data['currency'],
            'stock_quantity' => $data['stock_quantity'],
            'is_active' => $data['is_active'],
            'is_featured' => $data['is_featured'],
            'price_is_estimate' => $data['price_is_estimate'],
        ]);

        foreach ($data['translations'] as $locale => $translation) {
            $translation['slug'] .= $slugSuffix;
            $product->translations()->create(['locale' => $locale, ...$translation]);
        }

        return $product;
    }

    private function createCategory(): Category
    {
        $category = Category::create(['is_active' => true, 'position' => 0]);

        foreach ([
            'ar' => ['name' => 'هواتف', 'slug' => 'phones-ar'],
            'en' => ['name' => 'Phones', 'slug' => 'phones-en'],
            'he' => ['name' => 'טלפונים', 'slug' => 'phones-he'],
        ] as $locale => $translation) {
            $category->translations()->create(['locale' => $locale, ...$translation]);
        }

        return $category;
    }
}
