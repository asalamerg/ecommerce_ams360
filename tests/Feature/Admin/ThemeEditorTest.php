<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\SiteTheme;
use App\Models\ThemeSnapshot;
use App\Models\User;
use App\Services\ThemeManager;
use App\Support\Theme\ThemeCatalog;
use App\Support\Theme\ThemeStylesheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ThemeEditorTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserRole $role = UserRole::Administrator): User
    {
        return tap(User::factory()->create(), fn (User $user) => $user->forceFill(['role' => $role])->save());
    }

    /**
     * @return array<string, mixed>
     */
    private function theme(string $preset = 'ocean'): array
    {
        return [
            ...ThemeCatalog::defaultTheme(),
            'preset' => $preset,
            'colors' => ThemeCatalog::presets()[$preset],
        ];
    }

    private function saveDraft(User $admin, string $preset = 'ocean'): void
    {
        $this->actingAs($admin)
            ->put(route('admin.theme.draft'), ['theme' => $this->theme($preset), 'version' => app(ThemeManager::class)->version()])
            ->assertSessionHasNoErrors();
    }

    public function test_only_store_administrators_can_open_the_editor(): void
    {
        $this->get('/admin/theme')->assertRedirect(route('admin.login'));

        foreach ([UserRole::ProductManager, UserRole::SupportAgent, UserRole::Customer] as $role) {
            $this->actingAs($this->user($role))->get('/admin/theme')->assertForbidden();
        }

        $this->actingAs($this->user())->get('/admin/theme')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/theme/index')
            ->has('catalog.presets', 18));
    }

    public function test_a_product_manager_cannot_save_or_publish_a_theme(): void
    {
        $manager = $this->user(UserRole::ProductManager);

        $this->actingAs($manager)->put(route('admin.theme.draft'), ['theme' => $this->theme(), 'version' => 0])->assertForbidden();
        $this->actingAs($manager)->post(route('admin.theme.publish'), ['version' => 0])->assertForbidden();
    }

    public function test_a_draft_stays_private_until_it_is_published(): void
    {
        $admin = $this->user();
        $ocean = ThemeCatalog::presets()['ocean']['primary'];

        $this->saveDraft($admin);

        $this->post(route('logout'));
        $this->get('/ar')->assertDontSee($ocean);

        $this->actingAs($admin)->post(route('admin.theme.publish'), ['version' => app(ThemeManager::class)->version()]);
        $this->post(route('logout'));

        $this->get('/ar')->assertSee($ocean);
    }

    public function test_publishing_keeps_the_previous_theme_in_attributed_history(): void
    {
        $admin = $this->user();

        $this->saveDraft($admin, 'ocean');
        $this->post(route('admin.theme.publish'), ['version' => app(ThemeManager::class)->version()]);
        $this->saveDraft($admin, 'emerald');
        $this->post(route('admin.theme.publish'), ['version' => app(ThemeManager::class)->version()]);

        $latest = ThemeSnapshot::query()->where('kind', ThemeSnapshot::HISTORY)->latest('id')->firstOrFail();

        $this->assertSame('ocean', $latest->theme['preset']);
        $this->assertSame($admin->id, $latest->user_id);
        $this->assertDatabaseHas('store_admin_activities', ['section' => 'theme', 'action' => 'published', 'user_id' => $admin->id]);
    }

    public function test_history_is_capped(): void
    {
        $admin = $this->user();

        foreach (range(1, ThemeManager::HISTORY_LIMIT + 2) as $round) {
            $this->saveDraft($admin, $round % 2 === 0 ? 'ocean' : 'emerald');
            $this->post(route('admin.theme.publish'), ['version' => app(ThemeManager::class)->version()]);
        }

        $this->assertSame(ThemeManager::HISTORY_LIMIT, ThemeSnapshot::query()->where('kind', ThemeSnapshot::HISTORY)->count());
    }

    public function test_a_stale_editor_tab_cannot_overwrite_a_newer_change(): void
    {
        $admin = $this->user();

        $this->saveDraft($admin, 'ocean');

        $this->actingAs($admin)
            ->from('/admin/theme')
            ->put(route('admin.theme.draft'), ['theme' => $this->theme('rose'), 'version' => 0])
            ->assertSessionHasErrors('theme');

        $this->assertSame('ocean', app(ThemeManager::class)->draft()['preset']);
    }

    public function test_css_injection_is_rejected_on_save_and_ignored_in_stored_data(): void
    {
        $admin = $this->user();
        $attack = '#000000;}</style><script>alert(1)</script>';
        $theme = $this->theme();
        $theme['colors']['primary'] = $attack;

        $this->actingAs($admin)
            ->put(route('admin.theme.draft'), ['theme' => $theme, 'version' => 0])
            ->assertSessionHasErrors('theme.colors.primary');

        SiteTheme::query()->create(['id' => 1, 'theme' => ['colors' => ['primary' => $attack], 'font' => "x'}", 'radius' => 'nope'], 'version' => 0]);
        Cache::flush();

        $response = $this->get('/ar');

        $response->assertDontSee('alert(1)', false);
        $response->assertSee(ThemeCatalog::presets()['default']['primary'], false);
    }

    public function test_unknown_fonts_are_rejected(): void
    {
        $this->actingAs($this->user())
            ->put(route('admin.theme.draft'), ['theme' => [...$this->theme(), 'font' => 'comic-sans'], 'version' => 0])
            ->assertSessionHasErrors('theme.font');
    }

    public function test_discarding_returns_the_draft_to_the_published_theme(): void
    {
        $admin = $this->user();

        $this->saveDraft($admin);
        $this->post(route('admin.theme.discard'), ['version' => app(ThemeManager::class)->version()]);

        $this->assertSame('default', app(ThemeManager::class)->draft()['preset']);
    }

    public function test_a_history_entry_can_be_restored_into_the_draft_without_going_live(): void
    {
        $admin = $this->user();

        $this->saveDraft($admin, 'ocean');
        $this->post(route('admin.theme.publish'), ['version' => app(ThemeManager::class)->version()]);
        $snapshot = ThemeSnapshot::query()->where('kind', ThemeSnapshot::HISTORY)->firstOrFail();

        $this->post(route('admin.theme.snapshots.apply', $snapshot), ['version' => app(ThemeManager::class)->version()])
            ->assertSessionHasNoErrors();

        $this->assertSame('default', app(ThemeManager::class)->draft()['preset']);
        $this->assertSame('ocean', app(ThemeManager::class)->liveTheme()['preset']);
    }

    public function test_a_named_theme_can_be_saved_and_deleted(): void
    {
        $admin = $this->user();

        $this->saveDraft($admin, 'gold');
        $this->post(route('admin.theme.saved.store'), ['name' => 'Ramadan theme'])->assertSessionHasNoErrors();

        $snapshot = ThemeSnapshot::query()->where('kind', ThemeSnapshot::SAVED)->sole();
        $this->assertSame('gold', $snapshot->theme['preset']);

        $this->delete(route('admin.theme.snapshots.destroy', $snapshot))->assertRedirect();
        $this->assertModelMissing($snapshot);
    }

    public function test_a_running_schedule_wins_and_the_published_theme_returns_after_it(): void
    {
        $admin = $this->user();

        $this->saveDraft($admin, 'crimson');
        $this->post(route('admin.theme.schedules.store'), [
            'name' => 'Black Friday',
            'source' => 'draft',
            'starts_at' => now()->subHour()->toIso8601String(),
            'ends_at' => now()->addHour()->toIso8601String(),
        ])->assertSessionHasNoErrors();

        $this->assertSame('crimson', app(ThemeManager::class)->liveTheme()['preset']);

        $this->travel(2)->hours();
        Cache::flush();

        $this->assertSame('default', app(ThemeManager::class)->liveTheme()['preset']);
    }

    public function test_overlapping_schedules_are_refused(): void
    {
        $admin = $this->user();
        $schedule = fn (string $start, string $end) => $this->actingAs($admin)->from('/admin/theme')->post(route('admin.theme.schedules.store'), [
            'name' => 'Sale',
            'source' => 'draft',
            'starts_at' => now()->addDays((int) $start)->toIso8601String(),
            'ends_at' => now()->addDays((int) $end)->toIso8601String(),
        ]);

        $schedule('1', '3')->assertSessionHasNoErrors();
        $schedule('2', '4')->assertSessionHasErrors('starts_at');
        $schedule('3', '5')->assertSessionHasNoErrors();
    }

    public function test_only_the_admin_who_started_a_preview_sees_the_draft(): void
    {
        $admin = $this->user();
        $ocean = ThemeCatalog::presets()['ocean']['primary'];

        $this->saveDraft($admin);
        $this->get(route('admin.theme.preview'))->assertRedirect();

        $this->get('/ar')->assertSee($ocean)->assertInertia(fn (Assert $page) => $page->where('themePreview', true));

        $this->get(route('admin.theme.preview.exit'));
        $this->get('/ar')->assertDontSee($ocean);
    }

    public function test_a_customer_session_with_the_preview_flag_still_sees_the_live_theme(): void
    {
        $this->saveDraft($this->user());
        $this->post(route('logout'));

        $this->actingAs(User::factory()->create())
            ->withSession(['theme_preview' => true])
            ->get('/ar')
            ->assertDontSee(ThemeCatalog::presets()['ocean']['primary'])
            ->assertInertia(fn (Assert $page) => $page->where('themePreview', false));
    }

    public function test_theme_styles_load_after_the_stylesheet_they_override(): void
    {
        $html = $this->get('/ar')->getContent();

        $this->assertLessThan(
            strpos($html, '--brand-primary:'),
            strpos($html, 'stylesheet'),
            'The inline theme must come after app.css, or app.css cancels it.',
        );
    }

    public function test_dark_mode_is_only_emitted_when_the_theme_allows_it(): void
    {
        $theme = ThemeCatalog::defaultTheme();

        $this->assertStringNotContainsString('.dark', ThemeStylesheet::css($theme));
        $this->assertStringContainsString('.dark{', ThemeStylesheet::css([...$theme, 'darkMode' => true]));
    }

    public function test_every_preset_has_a_complete_palette_and_readable_main_text(): void
    {
        foreach (ThemeCatalog::presets() as $name => $colors) {
            $this->assertEqualsCanonicalizing(ThemeCatalog::TOKENS, array_keys($colors), $name);
            $this->assertSame([], ThemeStylesheet::contrastWarnings($colors), $name);
        }
    }

    public function test_brand_text_stays_readable_on_light_cards_for_every_preset(): void
    {
        foreach (ThemeCatalog::presets() as $name => $colors) {
            $accent = ThemeStylesheet::ensureContrast($colors['primary'], [$colors['card'], $colors['surface']]);

            $this->assertGreaterThanOrEqual(4.5, ThemeStylesheet::contrastRatio($accent, $colors['card']), $name);
        }
    }
}
