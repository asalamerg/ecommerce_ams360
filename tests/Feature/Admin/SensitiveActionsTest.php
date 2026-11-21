<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\TrustedProxies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreatesStoreData;
use Tests\TestCase;

class SensitiveActionsTest extends TestCase
{
    use CreatesStoreData;
    use RefreshDatabase;

    public function test_deleting_a_product_asks_for_the_password_and_returns_to_the_list(): void
    {
        $owner = $this->staff();
        $product = $this->makeProduct();

        $this->actingAsWithoutConfirmation($owner)
            ->from('/admin/products')
            ->delete("/admin/products/{$product->id}")
            ->assertRedirect(route('password.confirm'))
            ->assertSessionHas('url.intended', url('/admin/products'));

        $this->assertModelExists($product);
    }

    public function test_user_and_settings_forms_ask_for_the_password_but_other_modules_do_not(): void
    {
        $owner = $this->staff();
        $customer = User::factory()->create();

        $this->actingAsWithoutConfirmation($owner)
            ->get("/admin/modules/users/{$customer->id}/edit")
            ->assertRedirect(route('password.confirm'));

        $this->actingAsWithoutConfirmation($owner)
            ->put("/admin/modules/users/{$customer->id}", ['role' => 'administrator'])
            ->assertRedirect(route('password.confirm'));

        $this->assertSame(UserRole::Customer, $customer->fresh()->role);

        $this->actingAsWithoutConfirmation($owner)
            ->get('/admin/modules/brands/create')
            ->assertOk();
    }

    public function test_a_confirmation_lasts_for_the_configured_time(): void
    {
        $owner = $this->staff();
        $customer = User::factory()->create();

        $this->actingAsWithoutConfirmation($owner)
            ->withSession(['auth.password_confirmed_at' => time() - 60])
            ->get("/admin/modules/users/{$customer->id}/edit")
            ->assertOk();

        $this->actingAsWithoutConfirmation($owner)
            ->withSession(['auth.password_confirmed_at' => time() - config('auth.password_timeout') - 1])
            ->get("/admin/modules/users/{$customer->id}/edit")
            ->assertRedirect(route('password.confirm'));
    }

    public function test_idle_staff_are_signed_out_but_customers_are_not(): void
    {
        config(['store.staff_idle_minutes' => 30]);
        $idle = ['staff_last_seen_at' => time() - 31 * 60];

        $this->actingAs($this->staff())->withSession($idle)
            ->get('/admin/dashboard')
            ->assertRedirect(route('admin.login'));
        $this->assertGuest();

        $this->actingAs(User::factory()->create())->withSession($idle)
            ->get('/en/account')
            ->assertOk();
        $this->assertAuthenticated();
    }

    public function test_active_staff_stay_signed_in(): void
    {
        config(['store.staff_idle_minutes' => 30]);

        $this->actingAs($this->staff())->withSession(['staff_last_seen_at' => time() - 29 * 60])
            ->get('/admin/dashboard')
            ->assertOk();
    }

    public function test_only_cloudflare_may_report_the_visitor_address(): void
    {
        Route::get('/_test/ip', fn () => request()->ip());

        TrustProxies::at(TrustedProxies::addresses('cloudflare'));

        $this->withServerVariables(['REMOTE_ADDR' => '172.64.1.1'])
            ->get('/_test/ip', ['X-Forwarded-For' => '198.51.100.7'])
            ->assertSeeText('198.51.100.7');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->get('/_test/ip', ['X-Forwarded-For' => '198.51.100.7'])
            ->assertSeeText('203.0.113.9');

        TrustProxies::flushState();

        $this->assertSame([], TrustedProxies::addresses(''));
        $this->assertSame(['10.0.0.1', '10.0.0.0/8'], TrustedProxies::addresses(' 10.0.0.1, 10.0.0.0/8 '));
    }
}
