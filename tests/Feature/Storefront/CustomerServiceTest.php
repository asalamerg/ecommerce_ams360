<?php

namespace Tests\Feature\Storefront;

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\StaffAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesStoreData;
use Tests\TestCase;

class CustomerServiceTest extends TestCase
{
    use CreatesStoreData;
    use RefreshDatabase;

    private function branch(array $attributes = []): int
    {
        return DB::table('branches')->insertGetId([
            'name' => json_encode(['ar' => 'فرع حيفا', 'en' => 'Haifa branch', 'he' => 'סניף חיפה']),
            'address' => json_encode(['ar' => 'شارع هرتسل 1', 'en' => 'Herzl 1', 'he' => 'הרצל 1']),
            'working_hours' => json_encode(['sun' => ['closed' => false, 'open' => '09:00', 'close' => '19:00'], 'sat' => ['closed' => true]]),
            'lat' => 32.8,
            'lng' => 35.0,
            'is_active' => true,
            'allows_pickup' => true,
            ...$attributes,
        ]);
    }

    public function test_the_contact_page_shows_the_store_details_branches_and_policies(): void
    {
        $this->branch();
        $this->branch(['name' => json_encode(['en' => 'Closed branch']), 'is_active' => false]);
        DB::table('site_settings')->where('id', 1)->update([
            'delivery_info' => json_encode(['en' => 'Two working days.']),
            'return_policy' => json_encode(['en' => '14 days to return.']),
        ]);

        $this->get('/en/contact')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('storefront/contact')
            ->where('contact.phone', '+972 50-000-0000')
            ->has('branches', 1)
            ->where('branches.0.name', 'Haifa branch')
            ->where('branches.0.hours.sun.open', '09:00')
            ->where('branches.0.map_url', 'https://www.google.com/maps/search/?api=1&query=32.800000,35.000000')
            ->where('policies.delivery', 'Two working days.')
            ->where('policies.returns', '14 days to return.')
            ->where('seo.canonical', url('/en/contact')));
    }

    public function test_a_contact_message_reaches_the_admin_and_alerts_support_staff(): void
    {
        Notification::fake();
        $agent = $this->staff(UserRole::SupportAgent);
        $this->staff(UserRole::InventoryManager);

        $this->post('/en/contact', ['name' => 'Rami', 'phone' => '052-111-2233', 'message' => 'Do you deliver to Jenin?'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('contact_messages', ['name' => 'Rami', 'phone' => '052-111-2233', 'is_read' => false]);
        Notification::assertSentTo($agent, StaffAlert::class);
        Notification::assertSentTimes(StaffAlert::class, 1 + User::query()->where('role', 'owner')->count());
    }

    public function test_a_contact_message_needs_a_way_to_reply_and_a_real_phone(): void
    {
        $this->post('/en/contact', ['name' => 'Rami', 'message' => 'Hello there'])
            ->assertSessionHasErrors(['phone', 'email']);
        $this->post('/en/contact', ['name' => 'Rami', 'phone' => 'call me', 'message' => 'Hello there'])
            ->assertSessionHasErrors('phone');
        $this->post('/en/contact', ['name' => 'Rami', 'email' => 'rami@example.com', 'message' => 'Hello there'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('contact_messages', 1);
    }

    public function test_contact_messages_are_rate_limited(): void
    {
        foreach (range(1, 3) as $attempt) {
            $this->post('/en/contact', ['name' => 'Rami', 'phone' => '0521112233', 'message' => "Message {$attempt}"])->assertRedirect();
        }

        $this->post('/en/contact', ['name' => 'Rami', 'phone' => '0521112233', 'message' => 'One more'])->assertTooManyRequests();
        $this->assertDatabaseCount('contact_messages', 3);
    }

    public function test_a_repair_request_with_photos_is_saved_and_followed_from_the_account(): void
    {
        Storage::fake('public');
        $branch = $this->branch();
        $customer = User::factory()->create();

        $this->actingAs($customer)->post('/ar/repairs', [
            'device_type' => 'phone',
            'device_model' => 'iPhone 13',
            'issue' => 'Screen cracked after a fall',
            'contact_phone' => '0523334455',
            'branch_id' => $branch,
            'photos' => [UploadedFile::fake()->image('front.jpg', 800, 600)],
        ])->assertRedirect()->assertSessionHas('repair_reference');

        $repair = DB::table('maintenance_requests')->sole();
        $this->assertSame('هاتف', $repair->device_type);
        $this->assertSame($customer->id, (int) $repair->user_id);
        $photos = json_decode((string) $repair->image_urls, true);
        $this->assertCount(1, $photos);
        $this->assertStringStartsWith('/storage/uploads/maintenance_requests/', $photos[0]);
        Storage::disk('public')->assertExists(substr($photos[0], strlen('/storage/')));

        // Staff set a status and a cost; the customer sees both.
        DB::table('maintenance_requests')->update(['status' => 'ready', 'estimated_cost' => 250]);

        $this->actingAs($customer)->get('/ar/account')->assertInertia(fn (Assert $page) => $page
            ->has('repairs', 1)
            ->where('repairs.0.status', 'ready')
            ->where('repairs.0.estimated_cost', '250.00'));
    }

    public function test_a_repair_request_rejects_bad_input(): void
    {
        $this->post('/en/repairs', [
            'device_type' => 'toaster',
            'issue' => 'x',
            'contact_phone' => 'abc',
            'branch_id' => 999,
            'photos' => [UploadedFile::fake()->create('virus.exe', 10)],
        ])->assertSessionHasErrors(['device_type', 'issue', 'contact_phone', 'branch_id', 'photos.0']);

        $this->assertDatabaseCount('maintenance_requests', 0);
    }

    public function test_a_signed_in_customer_opens_a_ticket_and_follows_its_status(): void
    {
        $this->post('/en/account/tickets', ['subject' => 'Late', 'message' => 'Where is my order?'])->assertRedirect(route('login'));

        $customer = User::factory()->create();
        $other = User::factory()->create();
        DB::table('support_tickets')->insert(['user_id' => $other->id, 'subject' => 'Not mine', 'message' => 'Hidden', 'status' => 'open', 'created_at' => now()]);

        $this->actingAs($customer)->post('/en/account/tickets', ['subject' => 'Late order', 'message' => 'Where is my order?'])
            ->assertRedirect()->assertSessionHas('success');

        DB::table('support_tickets')->where('user_id', $customer->id)->update(['status' => 'in_progress']);

        $this->actingAs($customer)->get('/en/account')->assertInertia(fn (Assert $page) => $page
            ->has('tickets', 1)
            ->where('tickets.0.subject', 'Late order')
            ->where('tickets.0.status', 'in_progress'));
    }

    public function test_sim_plans_are_listed_with_their_features_and_linked_only_when_there_are_some(): void
    {
        $this->get('/en')->assertInertia(fn (Assert $page) => $page->where('footer.has_sim_plans', false));

        DB::table('sim_plans')->insert([
            'carrier' => 'Partner', 'name' => json_encode(['en' => '5G plan']), 'data_gb' => 100, 'minutes' => 3000,
            'monthly_price' => 49, 'contract_months' => 0, 'features' => json_encode(['Unlimited calls']), 'is_active' => true, 'sort_order' => 0,
        ]);
        DB::table('sim_plans')->insert([
            'carrier' => 'Cellcom', 'name' => json_encode(['en' => 'Old plan']), 'monthly_price' => 29, 'contract_months' => 0, 'is_active' => false, 'sort_order' => 0,
        ]);
        cache()->flush();

        $this->get('/en/sim-plans')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('storefront/sim-plans')
            ->has('plans', 1)
            ->where('plans.0.name', '5G plan')
            ->where('plans.0.features', ['Unlimited calls'])
            ->where('whatsapp', 'https://wa.me/972500000000')
            ->where('footer.has_sim_plans', true));
    }

    public function test_the_service_pages_are_in_the_sitemap(): void
    {
        $this->get('/sitemap.xml')->assertOk()
            ->assertSee(url('/ar/contact'), false)
            ->assertSee(url('/en/repairs'), false);
    }
}
