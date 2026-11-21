<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Support\StoreAdminModules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesStoreData;
use Tests\TestCase;

/**
 * Every admin module, the way staff use it: open the list, add an item filling
 * in Arabic only, open it, change it, and delete it where that is allowed.
 * The values are made from each field's own type and rules, so a module added
 * later is checked without writing a new test.
 */
class AdminModulesCrudTest extends TestCase
{
    use CreatesStoreData;
    use RefreshDatabase;

    private User $owner;

    /** @var array<string, int> */
    private array $related = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->staff();
        $product = $this->makeProduct();
        $this->related = [
            'products' => $product->id,
            'categories' => $this->makeCategory()->id,
            'branches' => (int) DB::table('branches')->insertGetId(['name' => json_encode(['ar' => 'فرع']), 'address' => json_encode(['ar' => 'حيفا']), 'is_active' => true]),
            'brands' => (int) DB::table('brands')->insertGetId(['slug' => 'crud-brand', 'name' => json_encode(['ar' => 'علامة']), 'sort_order' => 0, 'is_active' => true]),
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function modules(): array
    {
        return collect(array_keys(StoreAdminModules::all()))->mapWithKeys(fn (string $key): array => [$key => [$key]])->all();
    }

    #[DataProvider('modules')]
    public function test_each_module_can_be_listed_filled_in_arabic_saved_changed_and_deleted(string $key): void
    {
        $module = StoreAdminModules::find($key);
        $table = $module['table'];

        $this->actingAs($this->owner)->get("/admin/modules/{$key}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/module/index'));

        $id = $this->existingOrCreated($key, $module);

        $this->actingAs($this->owner)->get("/admin/modules/{$key}/{$id}/edit")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/module/form')
                // A select opens on a real option, never on an empty one.
                ->where('values', fn ($values) => collect($module['fields'])
                    ->filter(fn (array $field): bool => $field['type'] === 'select' && ! ($field['read_only'] ?? false))
                    ->every(fn (array $field): bool => filled($values[$field['name']] ?? null))));

        // A single-record screen stays on its form; the rest go back to their list.
        $this->actingAs($this->owner)->put("/admin/modules/{$key}/{$id}", $this->payload($module, $id))
            ->assertRedirect($module['singleton'] ? "/admin/modules/{$key}/{$id}/edit" : "/admin/modules/{$key}")
            ->assertSessionHasNoErrors();

        if (! $module['singleton']) {
            // The list it came from, with its filter, and the saved row marked.
            $this->actingAs($this->owner)->put("/admin/modules/{$key}/{$id}", [...$this->payload($module, $id), 'back' => "/admin/modules/{$key}?page=2"])
                ->assertRedirect("/admin/modules/{$key}?page=2")
                ->assertSessionHas('highlight', $id);
            $this->actingAs($this->owner)->put("/admin/modules/{$key}/{$id}", [...$this->payload($module, $id), 'after' => 'stay', 'back' => 'https://evil.example'])
                ->assertRedirect("/admin/modules/{$key}/{$id}/edit?back=%2Fadmin%2Fmodules%2F{$key}");
        }

        if ($module['can_delete']) {
            $this->actingAs($this->owner)->delete("/admin/modules/{$key}/{$id}")->assertRedirect("/admin/modules/{$key}");
            $this->assertDatabaseMissing($table, ['id' => $id]);
        }
    }

    public function test_errors_name_fields_as_the_form_does(): void
    {
        $this->actingAs($this->owner)->from('/admin/modules/coupons/create')->post('/admin/modules/coupons', ['code' => ''])
            ->assertSessionHasErrors(['code' => __('validation.required', ['attribute' => __('Coupon code')])]);
    }

    public function test_texts_left_empty_in_english_show_the_arabic_ones_in_the_store(): void
    {
        $this->actingAs($this->owner)->post('/admin/modules/hero-slides', [
            'image_url' => 'https://example.com/slide.webp', 'title_ar' => 'تخفيضات الشتاء', 'sort_order' => 0, 'is_active' => true,
        ])->assertSessionHasNoErrors();

        $this->get('/en')->assertInertia(fn (Assert $page) => $page->where('slides.0.title', 'تخفيضات الشتاء'));
    }

    /**
     * @param  array<string, mixed>  $module
     */
    private function existingOrCreated(string $key, array $module): int
    {
        if ($module['singleton']) {
            return 1;
        }

        if ($module['can_create']) {
            $before = DB::table($module['table'])->count();
            $this->actingAs($this->owner)->get("/admin/modules/{$key}/create")->assertOk();
            $this->actingAs($this->owner)->post("/admin/modules/{$key}", $this->payload($module, null))->assertSessionHasNoErrors()->assertRedirect();
            $this->assertSame($before + 1, DB::table($module['table'])->count(), "{$key}: nothing was saved");

            return (int) DB::table($module['table'])->max('id');
        }

        // Customers create these (repair requests, tickets, messages, reviews, accounts).
        return match ($module['table']) {
            'maintenance_requests' => (int) DB::table('maintenance_requests')->insertGetId(['device_type' => 'Phone', 'issue' => 'Broken screen', 'contact_phone' => '0501234567', 'status' => 'received', 'created_at' => now()]),
            'support_tickets' => (int) DB::table('support_tickets')->insertGetId(['subject' => 'Help', 'message' => 'Where is my order?', 'status' => 'open', 'created_at' => now()]),
            'contact_messages' => (int) DB::table('contact_messages')->insertGetId(['name' => 'Sara', 'message' => 'Hello', 'is_read' => false, 'created_at' => now()]),
            'reviews' => (int) DB::table('reviews')->insertGetId(['product_id' => $this->related['products'], 'customer_name' => 'Sara', 'rating' => 5, 'is_approved' => false, 'created_at' => now()]),
            'users' => User::factory()->create()->id,
        };
    }

    /**
     * Valid values for every editable field, Arabic only for texts.
     *
     * @param  array<string, mixed>  $module
     * @return array<string, mixed>
     */
    private function payload(array $module, ?int $id): array
    {
        $values = [];
        $unique = Str::lower(Str::random(6));

        foreach ($module['fields'] as $field) {
            if (($field['read_only'] ?? false) || ($field['virtual'] ?? false)) {
                continue;
            }

            if (isset($field['locale'])) {
                if ($field['locale'] === 'ar') {
                    $values[$field['name']] = $field['type'] === 'textarea' ? 'نص تجريبي طويل' : 'نص تجريبي';
                }

                continue;
            }

            $rules = (string) $field['rules'];
            $name = $field['name'];

            $values[$name] = match ($field['type']) {
                'checkbox' => true,
                'select' => array_key_first($field['options']),
                'email' => "test-{$unique}@example.com",
                'url' => 'https://example.com/page',
                'datetime-local' => $name === 'ends_at' ? '2026-12-31T10:00' : '2026-12-01T10:00',
                'image' => str_contains($rules, 'required') ? 'https://example.com/image.webp' : '',
                'relation' => $this->related[$field['relation']],
                'relations' => json_encode([$this->related[$field['relation']]]),
                'hours' => json_encode(['sun' => ['closed' => false, 'open' => '09:00', 'close' => '18:00'], 'sat' => ['closed' => true]]),
                'list' => json_encode(['ميزة أولى', 'ميزة ثانية']),
                'number' => match (true) {
                    $name === 'lat' => 32.8,
                    $name === 'lng' => 35.0,
                    default => 10,
                },
                default => match (true) {
                    str_contains($rules, 'alpha_dash') => "item-{$unique}",
                    $name === 'phone' || $name === 'whatsapp' => '0501234567',
                    default => 'Test value',
                },
            };
        }

        // Owners must keep their own access, and users are edited, not created.
        if ($module['table'] === 'users') {
            $values['role'] = 'customer';
            $values['preferred_locale'] = 'ar';
        }

        return $values;
    }
}
