<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * A small, known store for the browser tests in e2e/: one owner, one customer
 * with a completed order, two brands, a main section with two categories, a
 * delivery zone and a pickup branch. Never run against real data.
 */
class E2eSeeder extends Seeder
{
    public const string PASSWORD = 'E2e-password-123!';

    public function run(): void
    {
        abort_if(app()->isProduction(), 500, 'The E2E seeder never runs in production.');

        $owner = User::query()->create(['name' => 'Store Owner', 'email' => 'owner@e2e.test', 'password' => Hash::make(self::PASSWORD)]);
        $owner->forceFill(['role' => UserRole::Owner, 'email_verified_at' => now()])->save();

        $customer = User::query()->create(['name' => 'Sara Haddad', 'email' => 'sara@e2e.test', 'password' => Hash::make(self::PASSWORD), 'phone' => '0501234567']);
        $customer->forceFill(['email_verified_at' => now()])->save();

        $baseus = DB::table('brands')->insertGetId(['slug' => 'baseus', 'name' => json_encode(['ar' => 'بيسوس', 'en' => 'Baseus', 'he' => 'Baseus']), 'aliases' => 'باسيوس', 'sort_order' => 0, 'is_active' => true]);
        $anker = DB::table('brands')->insertGetId(['slug' => 'anker', 'name' => json_encode(['ar' => 'انكر', 'en' => 'Anker', 'he' => 'Anker']), 'sort_order' => 0, 'is_active' => true]);

        $section = $this->category('chargers', ['ar' => 'شواحن', 'en' => 'Chargers', 'he' => 'מטענים']);
        $car = $this->category('car-chargers', ['ar' => 'شواحن سيارة', 'en' => 'Car chargers', 'he' => 'מטעני רכב'], $section);
        $wall = $this->category('wall-chargers', ['ar' => 'شواحن جدارية', 'en' => 'Wall chargers', 'he' => 'מטעני קיר'], $section);

        $products = [
            $this->product('car-charger', ['ar' => 'شاحن سيارة سريع', 'en' => 'Fast car charger', 'he' => 'מטען רכב מהיר'], '89', 20, $car, $baseus),
            $this->product('wall-charger', ['ar' => 'شاحن جداري 65 واط', 'en' => '65W wall charger', 'he' => 'מטען קיר 65W'], '149', 15, $wall, $anker),
            $this->product('usb-cable', ['ar' => 'كابل USB-C', 'en' => 'USB-C cable', 'he' => 'כבל USB-C'], '35', 50, $wall, $baseus),
            $this->product('power-bank', ['ar' => 'بطارية متنقلة', 'en' => 'Power bank', 'he' => 'סוללה ניידת'], '199', 0, $car, $anker),
        ];

        DB::table('shipping_zones')->insert(['name' => json_encode(['ar' => 'الشمال', 'en' => 'North', 'he' => 'צפון']), 'fee' => 30, 'free_over' => 300, 'is_active' => true, 'sort_order' => 0]);
        DB::table('branches')->insert(['name' => json_encode(['ar' => 'فرع حيفا', 'en' => 'Haifa branch', 'he' => 'סניף חיפה']), 'address' => json_encode(['ar' => 'شارع هرتسل 1', 'en' => 'Herzl 1', 'he' => 'הרצל 1']), 'is_active' => true, 'allows_pickup' => true]);

        // A delivered order, so the customer may review the car charger.
        $order = Order::query()->create([
            'reference' => 'E2EDONE1', 'user_id' => $customer->id, 'customer_name' => $customer->name, 'phone' => '0501234567',
            'email' => $customer->email, 'shipping_address' => ['address' => 'Haifa'], 'item_count' => 1, 'subtotal' => '89',
            'discount_total' => '0', 'total' => '89', 'status' => 'completed', 'payment_method' => 'whatsapp',
            'payment_status' => 'paid', 'locale' => 'ar',
        ]);
        $order->items()->create(['product_id' => $products[0]->id, 'name' => ['ar' => 'شاحن سيارة سريع', 'en' => 'Fast car charger'], 'price' => '89', 'quantity' => 1]);
        OrderEvent::record($order, OrderEvent::CREATED, to: 'pending');

        // A WhatsApp order waiting for staff, for the order screens.
        $pending = Order::query()->create([
            'reference' => 'E2EPEND1', 'customer_name' => 'Omar Khalil', 'phone' => '+970 599 107 446', 'email' => 'omar@e2e.test',
            'shipping_address' => ['address' => 'Nablus'], 'item_count' => 2, 'subtotal' => '298', 'discount_total' => '0',
            'total' => '298', 'status' => 'pending', 'payment_method' => 'whatsapp', 'payment_status' => 'unpaid', 'locale' => 'ar',
        ]);
        $pending->items()->create(['product_id' => $products[1]->id, 'name' => ['ar' => 'شاحن جداري 65 واط', 'en' => '65W wall charger'], 'price' => '149', 'quantity' => 2, 'stock_deducted' => 2]);
        OrderEvent::record($pending, OrderEvent::CREATED, to: 'pending');

        // Things customers sent, waiting for staff.
        DB::table('reviews')->insert(['product_id' => $products[1]->id, 'customer_name' => 'Lina', 'rating' => 5, 'comment' => 'Charges fast', 'is_approved' => false, 'created_at' => now()]);
        DB::table('contact_messages')->insert(['name' => 'Rami', 'phone' => '0521112233', 'message' => 'Do you deliver to Jenin?', 'is_read' => false, 'created_at' => now()]);
        DB::table('maintenance_requests')->insert(['device_type' => 'Phone', 'device_model' => 'iPhone 13', 'issue' => 'Screen cracked', 'contact_phone' => '0523334455', 'status' => 'received', 'created_at' => now()]);
        DB::table('support_tickets')->insert(['subject' => 'Late order', 'message' => 'Where is my order?', 'status' => 'open', 'created_at' => now()]);
    }

    /**
     * @param  array<string, string>  $names
     */
    private function category(string $slug, array $names, ?Category $parent = null): Category
    {
        $category = Category::query()->create(['is_active' => true, 'position' => 1, 'parent_id' => $parent?->id]);

        foreach ($names as $locale => $name) {
            $category->translations()->create(['locale' => $locale, 'name' => $name, 'slug' => $locale === 'en' ? $slug : "{$slug}-{$locale}"]);
        }

        return $category;
    }

    /**
     * @param  array<string, string>  $names
     */
    private function product(string $slug, array $names, string $price, int $stock, Category $category, int $brand): Product
    {
        $product = Product::query()->create([
            'category_id' => $category->id, 'brand_id' => $brand, 'sku' => strtoupper($slug), 'price' => $price,
            'currency' => 'ILS', 'stock_quantity' => $stock, 'is_active' => true, 'is_featured' => false, 'price_is_estimate' => false,
        ]);

        foreach ($names as $locale => $name) {
            $product->translations()->create(['locale' => $locale, 'name' => $name, 'slug' => $locale === 'en' ? $slug : "{$slug}-{$locale}", 'description' => "{$name}."]);
        }

        return $product;
    }
}
