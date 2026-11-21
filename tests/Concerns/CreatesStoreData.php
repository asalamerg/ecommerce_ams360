<?php

namespace Tests\Concerns;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;

trait CreatesStoreData
{
    protected function staff(UserRole $role = UserRole::Owner): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    protected function makeCategory(string $name = 'Phones'): Category
    {
        $category = Category::create(['is_active' => true, 'position' => 1]);

        foreach (['ar', 'en', 'he'] as $locale) {
            $category->translations()->create(['locale' => $locale, 'name' => "{$name} {$locale}", 'slug' => "cat-{$category->id}-{$locale}"]);
        }

        return $category;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeProduct(array $attributes = [], string $name = 'Headset'): Product
    {
        $product = Product::create([
            'price' => '250.0000',
            'currency' => 'ILS',
            'stock_quantity' => 5,
            'is_active' => true,
            'is_featured' => false,
            ...$attributes,
        ]);

        foreach (['ar', 'en', 'he'] as $locale) {
            $product->translations()->create(['locale' => $locale, 'name' => "{$name} {$locale}", 'slug' => str($name)->slug()."-{$product->id}-{$locale}"]);
        }

        return $product->fresh(['translations']);
    }
}
