<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ThemeDraftRequest;
use App\Models\ThemeSnapshot;
use App\Services\ThemeManager;
use App\Support\Theme\ThemeCatalog;
use App\Support\Theme\ThemeStylesheet;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ThemeController extends Controller
{
    public function __construct(private ThemeManager $themes)
    {
        //
    }

    public function index(): Response
    {
        $draft = $this->themes->draft();
        $published = ThemeCatalog::normalize($this->themes->published());
        $active = $this->themes->activeSchedule();

        return Inertia::render('admin/theme/index', [
            'editor' => [
                'draft' => $draft,
                'version' => $this->themes->version(),
                'hasChanges' => $draft !== $published,
                'previewCss' => ThemeStylesheet::css($draft, '.theme-preview-box', '.theme-preview-box.preview-dark'),
                'warnings' => ThemeStylesheet::contrastWarnings($draft['colors']),
            ],
            'activeSchedule' => $active ? [
                'name' => $active->name,
                'ends_at' => $active->ends_at?->toIso8601String(),
            ] : null,
            'history' => $this->snapshots(ThemeSnapshot::HISTORY),
            'saved' => $this->snapshots(ThemeSnapshot::SAVED),
            'schedules' => $this->snapshots(ThemeSnapshot::SCHEDULE),
            'catalog' => [
                'presets' => ThemeCatalog::presets(),
                'groups' => ThemeCatalog::GROUPS,
                'fonts' => ThemeCatalog::FONTS,
                'radii' => array_keys(ThemeCatalog::RADII),
            ],
            'hasLogo' => filled(DB::table('site_settings')->where('id', 1)->value('logo_url')),
        ]);
    }

    public function saveDraft(ThemeDraftRequest $request): RedirectResponse
    {
        $this->themes->saveDraft($request->validated('theme'), (int) $request->validated('version'));

        return back();
    }

    public function discard(Request $request): RedirectResponse
    {
        $this->themes->discardDraft($request->integer('version'));

        return back()->with('success', __('Draft discarded.'));
    }

    /**
     * A full page load, because a client-side visit would keep the old theme styles.
     */
    public function publish(Request $request): HttpResponse
    {
        $this->themes->publish($request->integer('version'), $request->user());

        session()->flash('success', __('Theme published. Visitors now see it.'));

        return Inertia::location(route('admin.theme.index'));
    }

    public function apply(Request $request, ThemeSnapshot $snapshot): RedirectResponse
    {
        abort_if($snapshot->kind === ThemeSnapshot::SCHEDULE, 404);

        $this->themes->applySnapshot($snapshot, $request->integer('version'));

        return back()->with('success', __('Loaded into the draft. Publish it to go live.'));
    }

    public function saveNamed(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);

        $this->themes->saveNamed($data['name'], $request->user());

        return back()->with('success', __('Theme saved.'));
    }

    public function schedule(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'source' => ['required', 'string'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at', 'after:now'],
        ]);

        $theme = $data['source'] === 'draft'
            ? $this->themes->draft()
            : ThemeSnapshot::query()->where('kind', ThemeSnapshot::SAVED)->findOrFail((int) $data['source'])->theme;

        $this->themes->schedule(
            $data['name'],
            $theme,
            CarbonImmutable::parse($data['starts_at']),
            CarbonImmutable::parse($data['ends_at']),
            $request->user(),
        );

        return back()->with('success', __('Theme scheduled.'));
    }

    public function destroy(ThemeSnapshot $snapshot): RedirectResponse
    {
        abort_if($snapshot->kind === ThemeSnapshot::HISTORY, 404);

        $this->themes->deleteSnapshot($snapshot);

        return back()->with('success', __('Deleted.'));
    }

    /**
     * Only this admin's session sees the draft; the store stays the same for everyone else.
     */
    public function startPreview(Request $request): RedirectResponse
    {
        $request->session()->put('theme_preview', true);

        return to_route('storefront.home', ['locale' => App::getLocale()]);
    }

    public function stopPreview(Request $request): RedirectResponse
    {
        $request->session()->forget('theme_preview');

        return to_route('admin.theme.index');
    }

    /**
     * @return array<int, array{id: int, name: string|null, colors: array<string, string>, actor: string|null, created_at: string|null, starts_at: string|null, ends_at: string|null}>
     */
    private function snapshots(string $kind): array
    {
        return ThemeSnapshot::query()
            ->with('user:id,name')
            ->where('kind', $kind)
            ->latest('id')
            ->get()
            ->map(fn (ThemeSnapshot $snapshot): array => [
                'id' => $snapshot->id,
                'name' => $snapshot->name,
                'colors' => ThemeCatalog::normalize($snapshot->theme)['colors'],
                'actor' => $snapshot->user?->name,
                'created_at' => $snapshot->created_at?->toIso8601String(),
                'starts_at' => $snapshot->starts_at?->toIso8601String(),
                'ends_at' => $snapshot->ends_at?->toIso8601String(),
            ])
            ->all();
    }
}
