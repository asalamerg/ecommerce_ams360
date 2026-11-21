<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Order;
use App\Services\ProductSpreadsheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesStoreData;
use Tests\TestCase;

class ReportsAndSpreadsheetsTest extends TestCase
{
    use CreatesStoreData;
    use RefreshDatabase;

    private function sale(string $total, string $status = 'completed', ?int $productId = null, int $quantity = 1, ?string $coupon = null): Order
    {
        $order = Order::query()->create([
            'reference' => Str::upper(Str::random(8)), 'customer_name' => 'Sara', 'phone' => '0501234567',
            'item_count' => $quantity, 'subtotal' => $total, 'discount_total' => '0', 'total' => $total,
            'status' => $status, 'payment_method' => 'whatsapp', 'payment_status' => 'paid', 'locale' => 'ar',
            'coupon_code' => $coupon,
        ]);
        $order->items()->create(['product_id' => $productId, 'name' => ['ar' => 'سماعة', 'en' => 'Headset'], 'price' => bcdiv($total, (string) $quantity, 4), 'quantity' => $quantity]);

        return $order;
    }

    private function csv(string $contents): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('products.csv', $contents);
    }

    public function test_reports_add_up_sales_without_cancelled_orders(): void
    {
        $product = $this->makeProduct(['category_id' => $this->makeCategory()->id]);
        $this->sale('100', productId: $product->id, quantity: 2, coupon: 'SAVE');
        $this->sale('50', productId: $product->id);
        $this->sale('999', 'cancelled', $product->id);

        $this->actingAs($this->staff())->get('/admin/reports')->assertInertia(fn (Assert $page) => $page
            ->component('admin/reports/index')
            ->where('report.kpis.revenue', 150)
            ->where('report.kpis.orders', 2)
            ->where('report.kpis.average', 75)
            ->where('report.kpis.cancelled', 1)
            ->where('report.top_products.0.quantity', 3)
            ->where('report.top_products.0.name', 'سماعة')
            ->where('report.top_categories.0.revenue', 150)
            ->where('report.coupons.0.key', 'SAVE')
            ->has('report.days', 30));
    }

    public function test_reported_discounts_include_product_discounts_and_offers(): void
    {
        $sold = $this->sale('80');
        // Sold at 40 instead of 50, twice: 20 off.
        $sold->items()->update(['price' => '40', 'original_price' => '50', 'quantity' => 2]);
        $sold->update(['discount_total' => '5']);
        $cancelled = $this->sale('40', 'cancelled');
        $cancelled->items()->update(['price' => '40', 'original_price' => '90']);

        $this->actingAs($this->staff())->get('/admin/reports')->assertInertia(fn (Assert $page) => $page
            ->where('report.kpis.discounts', 25));
    }

    public function test_reports_and_the_order_export_are_for_owners_and_administrators(): void
    {
        $this->actingAs($this->staff(UserRole::OrderManager))->get('/admin/reports')->assertForbidden();
        $this->actingAs($this->staff(UserRole::OrderManager))->get('/admin/reports/orders.csv')->assertForbidden();
    }

    public function test_the_order_export_asks_for_the_password_and_neutralises_formulas(): void
    {
        $this->sale('10')->update(['customer_name' => '=HYPERLINK("https://evil.example","x")']);
        $owner = $this->staff();

        $this->actingAsWithoutConfirmation($owner)->get('/admin/reports/orders.csv')->assertRedirect(route('password.confirm'));

        $csv = $this->actingAs($owner)->get('/admin/reports/orders.csv')->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFreference,", $csv);
        $this->assertStringContainsString("\"'=HYPERLINK(", $csv);
    }

    public function test_bulk_actions_show_hide_and_confirm_prices(): void
    {
        $first = $this->makeProduct(['is_active' => false, 'price_is_estimate' => true]);
        $second = $this->makeProduct(['is_active' => false, 'price_is_estimate' => true]);
        $manager = $this->staff(UserRole::ProductManager);

        $this->actingAs($manager)->post('/admin/products/bulk', ['ids' => [$first->id, $second->id], 'action' => 'show'])->assertRedirect();
        $this->actingAs($manager)->post('/admin/products/bulk', ['ids' => [$first->id], 'action' => 'confirm_price'])->assertRedirect();

        $this->assertTrue($first->fresh()->is_active && $second->fresh()->is_active);
        $this->assertFalse($first->fresh()->price_is_estimate);
        $this->assertTrue($second->fresh()->price_is_estimate);
        $this->assertDatabaseHas('catalog_activities', ['action' => 'product.bulk_show', 'subject_id' => $second->id]);

        $this->actingAs($this->staff(UserRole::OrderManager))->post('/admin/products/bulk', ['ids' => [$first->id], 'action' => 'hide'])->assertForbidden();
    }

    public function test_an_export_can_be_edited_and_imported_back(): void
    {
        $product = $this->makeProduct(['sku' => 'HS-1', 'price' => '100', 'stock_quantity' => 5]);
        $owner = $this->staff();

        $export = $this->actingAs($owner)->get('/admin/products/export')->assertOk()->streamedContent();
        $this->assertStringContainsString("{$product->id},HS-1,", $export);

        $edited = "\xEF\xBB\xBFsku;price;stock;is_active;price_is_estimate\nHS-1;149.90;12;0;0\n";
        $this->actingAs($owner)->post('/admin/products/import', ['file' => $this->csv($edited)])
            ->assertRedirect()->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertSame(['149.9000', 12, false, false], [(string) $product->price, $product->stock_quantity, $product->is_active, $product->price_is_estimate]);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $product->id, 'stock_before' => 5, 'stock_after' => 12, 'reason' => 'Spreadsheet import']);
    }

    public function test_one_bad_row_means_nothing_is_imported(): void
    {
        $first = $this->makeProduct(['price' => '100']);
        $second = $this->makeProduct(['price' => '200']);

        $this->actingAs($this->staff())->post('/admin/products/import', [
            'file' => $this->csv("id,price\n{$first->id},90\n{$second->id},cheap\n99999,5\n"),
        ])->assertSessionHasErrors('file');

        $this->assertSame('100.0000', (string) $first->fresh()->price);
        $this->assertSame('200.0000', (string) $second->fresh()->price);
    }

    public function test_product_managers_cannot_change_stock_through_an_import(): void
    {
        $product = $this->makeProduct(['stock_quantity' => 5]);

        $this->actingAs($this->staff(UserRole::ProductManager))->post('/admin/products/import', [
            'file' => $this->csv("id,stock\n{$product->id},50\n"),
        ])->assertSessionHasErrors('file');

        $this->assertSame(5, $product->fresh()->stock_quantity);
    }

    public function test_formula_cells_are_neutralised_but_numbers_are_not(): void
    {
        $this->assertSame("'=1+1", ProductSpreadsheet::safeCell('=1+1'));
        $this->assertSame("'@SUM(A1)", ProductSpreadsheet::safeCell('@SUM(A1)'));
        $this->assertSame('-5', ProductSpreadsheet::safeCell('-5'));
        $this->assertSame('Headset', ProductSpreadsheet::safeCell('Headset'));
    }
}
