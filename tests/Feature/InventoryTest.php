<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_inventory_management(): void
    {
        $this->get('/admin/inventory')->assertRedirect(route('admin.login'));
    }

    public function test_product_manager_cannot_view_or_adjust_inventory(): void
    {
        $product = $this->createProduct();
        $manager = $this->userWithRole(UserRole::ProductManager);

        $this->actingAs($manager)
            ->get('/admin/inventory')
            ->assertForbidden();

        $this->actingAs($manager)
            ->post("/admin/inventory/{$product->id}/adjust", $this->adjustmentData())
            ->assertForbidden();

        $this->assertSame(4, $product->fresh()->stock_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_inventory_manager_can_adjust_stock_and_audits_the_change(): void
    {
        $manager = $this->userWithRole(UserRole::InventoryManager);
        $product = $this->createProduct();

        $this->actingAs($manager)
            ->get('/admin/inventory')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/inventory/index')
                ->where('products.data.0.stock_quantity', 4)
                ->has('movements.data', 0),
            );

        $this->actingAs($manager)
            ->post("/admin/inventory/{$product->id}/adjust", $this->adjustmentData())
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(7, $product->fresh()->stock_quantity);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'user_id' => $manager->id,
            'direction' => 'increase',
            'quantity_delta' => 3,
            'stock_before' => 4,
            'stock_after' => 7,
            'reason' => 'New shipment received',
        ]);
    }

    public function test_stock_cannot_be_decreased_below_zero(): void
    {
        $manager = $this->userWithRole(UserRole::InventoryManager);
        $product = $this->createProduct();

        $this->actingAs($manager)
            ->from('/admin/inventory')
            ->post("/admin/inventory/{$product->id}/adjust", [
                ...$this->adjustmentData(),
                'direction' => 'decrease',
                'quantity' => 5,
            ])
            ->assertRedirect('/admin/inventory')
            ->assertSessionHasErrors('quantity');

        $this->assertSame(4, $product->fresh()->stock_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_stock_cannot_exceed_the_supported_unsigned_quantity(): void
    {
        $manager = $this->userWithRole(UserRole::InventoryManager);
        $product = $this->createProduct();

        $this->actingAs($manager)
            ->from('/admin/inventory')
            ->post("/admin/inventory/{$product->id}/adjust", [
                ...$this->adjustmentData(),
                'quantity' => 4294967295,
            ])
            ->assertRedirect('/admin/inventory')
            ->assertSessionHasErrors('quantity');

        $this->assertSame(4, $product->fresh()->stock_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_adjustment_requires_a_valid_reason_and_positive_quantity(): void
    {
        $manager = $this->userWithRole(UserRole::InventoryManager);
        $product = $this->createProduct();

        $this->actingAs($manager)
            ->from('/admin/inventory')
            ->post("/admin/inventory/{$product->id}/adjust", [
                'direction' => 'increase',
                'quantity' => 0,
                'reason' => '',
            ])
            ->assertRedirect('/admin/inventory')
            ->assertSessionHasErrors(['quantity', 'reason']);

        $this->assertSame(4, $product->fresh()->stock_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    private function userWithRole(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    private function createProduct(): Product
    {
        $product = Product::create([
            'sku' => 'PHONE-001',
            'price' => '129.9000',
            'currency' => 'ILS',
            'stock_quantity' => 4,
            'is_active' => true,
            'is_featured' => false,
        ]);

        $product->translations()->create([
            'locale' => 'ar',
            'name' => 'هاتف',
            'slug' => 'phone-ar',
        ]);

        return $product;
    }

    /**
     * @return array{direction: string, quantity: int, reason: string}
     */
    private function adjustmentData(): array
    {
        return [
            'direction' => 'increase',
            'quantity' => 3,
            'reason' => 'New shipment received',
        ];
    }
}
