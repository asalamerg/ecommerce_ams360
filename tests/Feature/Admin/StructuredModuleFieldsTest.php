<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesStoreData;
use Tests\TestCase;

class StructuredModuleFieldsTest extends TestCase
{
    use CreatesStoreData;
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function offer(array $overrides = []): array
    {
        return [
            'title_ar' => 'عرض', 'title_en' => 'Offer', 'title_he' => 'מבצע',
            'discount_type' => 'percentage', 'discount_value' => 10, 'is_active' => true,
            ...$overrides,
        ];
    }

    public function test_offer_products_and_category_are_picked_from_lists(): void
    {
        $first = $this->makeProduct([], 'Charger');
        $second = $this->makeProduct([], 'Cable');
        $category = $this->makeCategory();
        $admin = $this->staff();

        $this->actingAs($admin)->get('/admin/modules/offers/create')->assertInertia(fn (Assert $page) => $page
            ->where('module.fields', fn ($fields) => collect($fields)->firstWhere('name', 'product_ids')['type'] === 'relations'
                && collect(collect($fields)->firstWhere('name', 'product_ids')['choices'])->pluck('value')->contains((string) $first->id)
                && collect($fields)->firstWhere('name', 'category_id')['type'] === 'relation'));

        $this->actingAs($admin)->post('/admin/modules/offers', $this->offer([
            'product_ids' => json_encode([$second->id, (string) $first->id, $second->id]),
            'category_id' => $category->id,
        ]))->assertSessionHasNoErrors();

        $row = DB::table('offers')->first();
        // MySQL keeps JSON in its own spacing, so the decoded values are compared.
        $this->assertSame([$second->id, $first->id], json_decode($row->product_ids, true));
        $this->assertSame($category->id, (int) $row->category_id);
    }

    public function test_offers_reject_products_that_do_not_exist_or_malformed_lists(): void
    {
        $admin = $this->staff();

        $this->actingAs($admin)->post('/admin/modules/offers', $this->offer(['product_ids' => '[999]']))
            ->assertSessionHasErrors('product_ids');
        $this->actingAs($admin)->post('/admin/modules/offers', $this->offer(['product_ids' => '{"a":1}']))
            ->assertSessionHasErrors('product_ids');
        $this->actingAs($admin)->post('/admin/modules/offers', $this->offer(['product_ids' => '["1 or 1=1"]']))
            ->assertSessionHasErrors('product_ids');

        $this->assertDatabaseCount('offers', 0);
    }

    public function test_branch_opening_hours_are_checked_and_cleaned(): void
    {
        $admin = $this->staff();
        $branch = [
            'name_ar' => 'الفرع', 'name_en' => 'Branch', 'name_he' => 'סניף',
            'address_ar' => 'حيفا', 'address_en' => 'Haifa', 'address_he' => 'חיפה',
            'allows_pickup' => true, 'is_active' => true,
        ];

        $this->actingAs($admin)->post('/admin/modules/branches', [...$branch, 'working_hours' => json_encode(['mon' => ['closed' => false, 'open' => '25:00', 'close' => '18:00']])])
            ->assertSessionHasErrors('working_hours');

        $this->actingAs($admin)->post('/admin/modules/branches', [...$branch, 'working_hours' => json_encode([
            'sat' => ['closed' => true],
            'sun' => ['closed' => false, 'open' => '09:00', 'close' => '19:30', 'extra' => '<script>'],
        ])])->assertSessionHasNoErrors();

        $this->assertEquals(
            ['sun' => ['closed' => false, 'open' => '09:00', 'close' => '19:30'], 'sat' => ['closed' => true]],
            json_decode(DB::table('branches')->value('working_hours'), true),
        );
    }

    public function test_plan_features_are_a_clean_list_of_lines(): void
    {
        $this->actingAs($this->staff())->post('/admin/modules/sim-plans', [
            'carrier' => 'Partner', 'name_ar' => 'باقة', 'name_en' => 'Plan', 'name_he' => 'חבילה',
            'monthly_price' => 49, 'contract_months' => 0, 'sort_order' => 0, 'is_active' => true,
            'features' => json_encode(['  5G  ', '', 'Unlimited calls']),
        ])->assertSessionHasNoErrors();

        $this->assertSame(['5G', 'Unlimited calls'], json_decode(DB::table('sim_plans')->value('features'), true));
    }
}
