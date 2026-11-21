<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\AuthEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StoreAccessTest extends TestCase
{
    use RefreshDatabase;

    private function staff(UserRole $role = UserRole::Administrator): User
    {
        return tap(User::factory()->create(), fn (User $user) => $user->forceFill(['role' => $role])->save());
    }

    public function test_guests_opening_the_admin_panel_are_sent_to_the_staff_login(): void
    {
        $this->get('/admin/dashboard')->assertRedirect(route('admin.login'));
    }

    public function test_the_staff_login_page_renders_in_staff_mode(): void
    {
        $this->get(route('admin.login'))->assertInertia(fn (Assert $page) => $page
            ->component('auth/login')
            ->where('staffLogin', true));
    }

    public function test_an_administrator_signs_in_at_the_staff_login_and_reaches_the_admin_panel(): void
    {
        $admin = $this->staff();

        $this->post(route('admin.login.store'), ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin);
        $this->get(route('admin.dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/dashboard')
            ->where('auth.user.is_staff', true));
    }

    public function test_an_administrator_signing_in_at_the_customer_login_also_reaches_the_admin_panel(): void
    {
        $admin = $this->staff();

        $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_each_staff_role_lands_on_its_own_work_area(): void
    {
        $expected = [
            [UserRole::Owner, route('admin.dashboard')],
            [UserRole::ProductManager, route('admin.products.index')],
            [UserRole::InventoryManager, route('admin.inventory.index')],
            [UserRole::OrderManager, route('admin.orders.index')],
            [UserRole::SupportAgent, route('admin.modules.index', ['module' => 'support'])],
        ];

        foreach ($expected as [$role, $url]) {
            $staff = $this->staff($role);

            $this->post(route('admin.login.store'), ['email' => $staff->email, 'password' => 'password'])
                ->assertRedirect($url);

            $this->get($url)->assertOk();
            $this->post(route('logout'));
        }
    }

    public function test_a_customer_cannot_sign_in_at_the_staff_login_and_learns_nothing_about_why(): void
    {
        $customer = User::factory()->create();

        $wrongPassword = $this->from(route('admin.login'))
            ->post(route('admin.login.store'), ['email' => $customer->email, 'password' => 'wrong-password']);
        $rightPassword = $this->from(route('admin.login'))
            ->post(route('admin.login.store'), ['email' => $customer->email, 'password' => 'password']);

        $this->assertGuest();
        $wrongPassword->assertRedirect(route('admin.login'))->assertSessionHasErrors(['email' => __('auth.failed')]);
        $rightPassword->assertRedirect(route('admin.login'))->assertSessionHasErrors(['email' => __('auth.failed')]);
    }

    public function test_a_customer_signed_in_normally_is_refused_by_the_admin_panel(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/dashboard')->assertForbidden();
    }

    public function test_a_customer_bounced_off_the_admin_panel_lands_on_the_store_after_login(): void
    {
        $customer = User::factory()->create();

        $this->get('/admin/products')->assertRedirect(route('admin.login'));

        $this->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])
            ->assertRedirect(route('storefront.home', ['locale' => 'ar']));
    }

    public function test_staff_return_to_the_admin_page_they_originally_opened(): void
    {
        $admin = $this->staff();

        $this->get('/admin/products');

        $this->post(route('admin.login.store'), ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect('/admin/products');
    }

    public function test_an_administrator_with_two_factor_reaches_the_admin_panel_after_the_challenge(): void
    {
        $admin = $this->staff();
        $admin->forceFill([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->post(route('admin.login.store'), ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('two-factor.login'));
        $this->assertGuest();

        $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin);
    }

    public function test_signed_in_users_opening_a_login_page_go_to_their_home(): void
    {
        $admin = $this->staff();

        $this->actingAs($admin)->get(route('login'))->assertRedirect(route('dashboard'));
        $this->actingAs($admin)->get(route('admin.login'))->assertRedirect(route('dashboard'));
        $this->actingAs($admin)->get(route('dashboard'))->assertRedirect(route('admin.dashboard'));
    }

    public function test_registration_always_creates_a_customer_even_if_a_role_is_submitted(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Sneaky',
            'email' => 'sneaky@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'owner',
            'is_active' => false,
        ]);

        $user = User::query()->where('email', 'sneaky@example.com')->sole();

        $this->assertSame(UserRole::Customer, $user->role);
        $this->assertTrue($user->is_active);

        $user->markEmailAsVerified();
        $this->actingAs($user)->get('/admin/dashboard')->assertForbidden();
    }

    public function test_a_new_customer_is_stored_with_the_language_they_registered_in(): void
    {
        $this->get('/he');

        $this->post(route('register.store'), [
            'name' => 'Dana',
            'email' => 'dana@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('storefront.home', ['locale' => 'he']));

        $this->assertSame('he', User::query()->where('email', 'dana@example.com')->sole()->preferred_locale);
    }

    public function test_registration_is_rate_limited(): void
    {
        RateLimiter::clear('auth-forms');

        foreach (range(1, 5) as $attempt) {
            $this->post(route('register.store'), ['email' => 'not-an-email'])->assertRedirect();
        }

        $this->post(route('register.store'), ['email' => 'not-an-email'])->assertTooManyRequests();
    }

    public function test_the_staff_login_shares_the_login_rate_limit(): void
    {
        $admin = $this->staff();

        foreach (range(1, 5) as $attempt) {
            $this->post(route('admin.login.store'), ['email' => $admin->email, 'password' => 'wrong-password']);
        }

        $this->post(route('admin.login.store'), ['email' => $admin->email, 'password' => 'password'])
            ->assertTooManyRequests();
        $this->assertGuest();
    }

    public function test_the_password_reset_form_does_not_reveal_whether_an_account_exists(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $known = $this->post(route('password.email'), ['email' => $user->email]);
        $knownStatus = session('status');

        $unknown = $this->post(route('password.email'), ['email' => 'nobody@example.com']);

        $known->assertSessionHasNoErrors();
        $unknown->assertSessionHasNoErrors();
        $this->assertSame($knownStatus, session('status'));
    }

    public function test_a_disabled_account_cannot_sign_in_with_the_right_password(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['is_active' => false])->save();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => __('auth.failed')]);

        $this->assertGuest();
    }

    public function test_disabling_an_account_ends_its_active_session(): void
    {
        $admin = $this->staff();

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();

        $admin->forceFill(['is_active' => false])->save();

        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_only_an_owner_can_disable_an_administrator_and_nobody_can_disable_themselves(): void
    {
        $owner = $this->staff(UserRole::Owner);
        $admin = $this->staff();
        $otherAdmin = $this->staff();
        $payload = fn (User $user, bool $active) => [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => '',
            'preferred_locale' => 'ar',
            'role' => $user->role->value,
            'is_active' => $active,
        ];
        $url = fn (User $user) => route('admin.modules.update', ['module' => 'users', 'record' => $user->id]);

        $this->actingAs($admin)->put($url($otherAdmin), $payload($otherAdmin, false))->assertForbidden();
        $this->actingAs($admin)->put($url($admin), $payload($admin, false))->assertStatus(422);
        $this->assertTrue($otherAdmin->fresh()->is_active);

        $this->actingAs($owner)->put($url($otherAdmin), $payload($otherAdmin, false))->assertRedirect();
        $this->assertFalse($otherAdmin->fresh()->is_active);
    }

    public function test_staff_are_sent_to_set_up_two_factor_when_it_is_required(): void
    {
        config(['store.require_staff_two_factor' => true]);
        $admin = $this->staff();

        $this->actingAs($admin)->get('/admin/dashboard')->assertRedirect(route('security.edit'));

        $admin->forceFill([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($admin)->get('/admin/dashboard')->assertOk();
    }

    public function test_sign_in_activity_is_recorded_without_any_credentials(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'super-secret-guess']);
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $this->post(route('logout'));

        $this->assertSame(
            ['failed', 'login', 'logout'],
            AuthEvent::query()->where('user_id', $user->id)->orderBy('id')->pluck('event')->all(),
        );
        $this->assertStringNotContainsString(
            'super-secret-guess',
            AuthEvent::query()->get()->toJson(),
        );
    }

    public function test_sign_in_activity_is_visible_to_administrators_and_not_to_product_managers(): void
    {
        $user = User::factory()->create();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $this->post(route('logout'));

        $this->actingAs($this->staff())->get('/admin/activity')
            ->assertInertia(fn (Assert $page) => $page->has('authEvents', 2));

        $this->actingAs($this->staff(UserRole::ProductManager))->get('/admin/activity')
            ->assertInertia(fn (Assert $page) => $page->has('authEvents', 0));
    }

    public function test_auth_pages_follow_the_visitors_language_and_direction(): void
    {
        $this->get('/login')->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'ar')
            ->where('direction', 'rtl')
            ->where('ui.ar.auth.login', 'تسجيل الدخول'));

        $this->get('/login?lang=en')->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'en')
            ->where('direction', 'ltr'));

        $this->get('/register')->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
    }

    public function test_login_errors_are_translated(): void
    {
        $user = User::factory()->create();

        $this->get('/he');
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors(['email' => 'האימייל או הסיסמה שגויים.']);
    }

    public function test_an_unsupported_language_request_is_ignored(): void
    {
        $this->get('/login?lang=xx')->assertInertia(fn (Assert $page) => $page->where('locale', 'ar'));
    }

    public function test_guests_cannot_open_the_account_page(): void
    {
        $this->get('/ar/account')->assertRedirect(route('login'));
    }

    public function test_customers_can_update_their_own_details_but_not_their_role(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/en/account', [
            'name' => 'New Name',
            'phone' => '+972 50-123-4567',
            'preferred_locale' => 'he',
            'role' => 'owner',
            'is_active' => false,
            'email' => 'stolen@example.com',
        ])->assertRedirect();

        $user->refresh();

        $this->assertSame('New Name', $user->name);
        $this->assertSame('he', $user->preferred_locale);
        $this->assertSame(UserRole::Customer, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertNotSame('stolen@example.com', $user->email);
    }

    public function test_changing_a_password_requires_the_current_one(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/en/account/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertSessionHasErrors('current_password');

        $this->actingAs($user)->put('/en/account/password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertSessionHasNoErrors();

        $this->post(route('logout'));
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'new-password']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_create_admin_command_creates_a_verified_staff_account(): void
    {
        $this->artisan('store:create-admin', ['email' => 'owner@example.com'])
            ->expectsQuestion('Name', 'Store Owner')
            ->expectsQuestion('Password', 'a-long-password')
            ->expectsQuestion('Confirm password', 'a-long-password')
            ->assertSuccessful();

        $owner = User::query()->where('email', 'owner@example.com')->sole();

        $this->assertSame(UserRole::Owner, $owner->role);
        $this->assertTrue($owner->hasVerifiedEmail());
        $this->post(route('admin.login.store'), ['email' => 'owner@example.com', 'password' => 'a-long-password'])
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_the_create_admin_command_refuses_a_customer_role(): void
    {
        $this->artisan('store:create-admin', ['email' => 'x@example.com', '--role' => 'customer'])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'x@example.com']);
    }
}
