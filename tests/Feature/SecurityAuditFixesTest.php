<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Findings from the November 2026 security and feature audit.
 */
class SecurityAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    public function test_administrators_can_create_and_delete_coupons_and_offers(): void
    {
        $admin = $this->userWithRole(UserRole::Administrator);

        $this->actingAs($admin)->get('/admin/modules/coupons/create')->assertOk();
        $this->actingAs($admin)->post('/admin/modules/coupons', [
            'code' => 'WELCOME10', 'discount_type' => 'percentage', 'discount_value' => 10, 'max_uses' => '', 'expires_at' => '', 'is_active' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $id = (int) DB::table('coupons')->where('code', 'WELCOME10')->value('id');
        $this->assertGreaterThan(0, $id);

        $this->actingAs($admin)->delete("/admin/modules/coupons/{$id}")->assertRedirect();
        $this->assertDatabaseMissing('coupons', ['id' => $id]);

        $this->actingAs($admin)->get('/admin/modules/offers/create')->assertOk();
    }

    public function test_coupons_stay_closed_to_catalog_and_order_staff(): void
    {
        foreach ([UserRole::ProductManager, UserRole::OrderManager, UserRole::Customer] as $role) {
            $this->actingAs($this->userWithRole($role))
                ->post('/admin/modules/coupons', ['code' => 'FREE', 'discount_type' => 'fixed', 'discount_value' => 1000, 'is_active' => true])
                ->assertForbidden();
        }

        $this->assertDatabaseCount('coupons', 0);
    }

    public function test_deleting_a_record_deletes_its_uploaded_image(): void
    {
        Storage::fake('public');
        $admin = $this->userWithRole(UserRole::Administrator);

        $this->actingAs($admin)->post('/admin/modules/hero-slides', [
            'image_url' => '', 'image_url_file' => UploadedFile::fake()->image('slide.jpg', 400, 200),
            'title_ar' => 'عرض', 'title_en' => 'Offer', 'title_he' => 'מבצע',
            'subtitle_ar' => '', 'subtitle_en' => '', 'subtitle_he' => '', 'link_url' => '', 'sort_order' => 0, 'is_active' => true,
        ])->assertSessionHasNoErrors();
        $slide = DB::table('hero_slides')->sole();
        $path = substr((string) $slide->image_url, strlen('/storage/'));
        Storage::disk('public')->assertExists($path);

        $this->actingAs($admin)->delete("/admin/modules/hero-slides/{$slide->id}")->assertRedirect();

        Storage::disk('public')->assertMissing($path);
    }

    public function test_production_only_answers_to_its_own_host(): void
    {
        $this->assertTrue($this->app->make(Kernel::class)->hasMiddleware(TrustHosts::class));

        // Laravel skips the check while tests run, so check what it would trust.
        config(['app.url' => 'https://myshop.ps']);
        $patterns = (new TrustHosts($this->app))->hosts();
        $trusted = fn (string $host): bool => collect($patterns)->contains(fn (string $pattern): bool => preg_match("#{$pattern}#", $host) === 1);

        $this->assertTrue($trusted('myshop.ps'));
        $this->assertTrue($trusted('www.myshop.ps'));
        // The container health check (docker/Caddyfile), reachable only from inside.
        $this->assertTrue($trusted('127.0.0.1'));
        $this->assertFalse($trusted('evil.example'));
        $this->assertFalse($trusted('myshop.ps.evil.example'));
        $this->assertFalse($trusted('127.0.0.1.evil.example'));
        $this->assertFalse($trusted('localhost'));
    }

    public function test_links_in_emails_use_app_url_in_production_whatever_the_host_header(): void
    {
        config(['app.url' => 'https://myshop.ps']);
        $this->app['env'] = 'production';
        $this->app->instance('request', $this->app['request']->duplicate(server: ['HTTP_HOST' => 'evil.example']));
        (new AppServiceProvider($this->app))->boot();

        $this->assertSame('https://myshop.ps/reset-password/token', url('/reset-password/token'));
    }
}
