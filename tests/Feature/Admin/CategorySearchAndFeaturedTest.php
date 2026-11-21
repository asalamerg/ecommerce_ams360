<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesStoreData;
use Tests\TestCase;

class CategorySearchAndFeaturedTest extends TestCase
{
    use CreatesStoreData;
    use RefreshDatabase;

    private function category(string $ar, string $en, ?Category $parent = null): Category
    {
        $category = Category::create(['is_active' => true, 'position' => 1, 'parent_id' => $parent?->id]);
        $category->translations()->create(['locale' => 'ar', 'name' => $ar, 'slug' => str($en)->slug().'-ar']);
        $category->translations()->create(['locale' => 'en', 'name' => $en, 'slug' => str($en)->slug().'-en']);

        return $category;
    }

    public function test_categories_are_found_by_any_language_spelling_or_main_section(): void
    {
        $chargers = $this->category('شواحن', 'Chargers');
        $car = $this->category('شواحن سيارة', 'Car chargers', $chargers);
        $cases = $this->category('أغطية الهواتف', 'Phone cases');
        $admin = $this->staff(UserRole::ProductManager);

        $ids = fn (string $query): array => $this->actingAs($admin)->get('/admin/categories?q='.urlencode($query))
            ->assertOk()->viewData('page')['props']['categories']['data'];

        // ة and ه are one letter for search, as in the store.
        $this->assertSame([$car->id], array_column($ids('سياره'), 'id'));
        $this->assertSame([$cases->id], array_column($ids('اغطية'), 'id'));
        $this->assertSame([$cases->id], array_column($ids('phone cases'), 'id'));
        // A main section's name finds the categories under it too.
        $this->assertEqualsCanonicalizing([$chargers->id, $car->id], array_column($ids('شواحن'), 'id'));
        $this->assertSame([], $ids('لا يوجد'));

        $this->actingAs($admin)->get('/admin/categories?q=car')->assertInertia(fn (Assert $page) => $page
            ->where('search', 'car')
            ->has('categories.data', 1));
    }

    public function test_featured_products_have_their_own_filter_bulk_actions_and_menu_item(): void
    {
        $admin = $this->staff(UserRole::ProductManager);
        $first = $this->makeProduct();
        $second = $this->makeProduct();
        $this->makeProduct();

        $this->actingAs($admin)->post('/admin/products/bulk', ['ids' => [$first->id, $second->id], 'action' => 'feature'])
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)->get('/admin/products?status=featured')->assertInertia(fn (Assert $page) => $page
            ->where('status', 'featured')
            ->where('counts.featured', 2)
            ->has('products.data', 2)
            ->where('products.data.0.is_featured', true)
            ->where('admin.groups', fn ($groups) => collect($groups)->flatMap(fn ($group) => $group['items'])
                ->contains('href', '/admin/products?status=featured')));

        $this->get('/en')->assertInertia(fn (Assert $page) => $page->has('featured', 2));
        // "View all" on that row lists them, and only them.
        $this->get('/en/products?featured=1')->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 2)
            ->where('filters.featured', true)
            ->where('seo.robots', 'noindex, follow'));

        $this->actingAs($admin)->post('/admin/products/bulk', ['ids' => [$first->id], 'action' => 'unfeature']);
        $this->assertFalse($first->fresh()->is_featured);
    }
}
