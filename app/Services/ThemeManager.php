<?php

namespace App\Services;

use App\Models\SiteTheme;
use App\Models\ThemeSnapshot;
use App\Models\User;
use App\Support\Theme\ThemeCatalog;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns the theme lifecycle: draft, publish, history, saved themes and schedules.
 * Every write runs in a transaction on a locked row and checks the editor's
 * version, so a stale tab cannot silently undo a newer change.
 */
class ThemeManager
{
    public const int HISTORY_LIMIT = 10;

    public const int SAVED_LIMIT = 30;

    private const string CACHE_KEY = 'store.live-theme';

    /** How long a schedule can take to start or end on the live store. */
    private const int CACHE_SECONDS = 60;

    /**
     * The theme visitors see now: a running schedule wins over the published theme.
     *
     * @return array{preset: string, colors: array<string, string>, font: string, radius: string, darkMode: bool, logoFavicon: bool}
     */
    public function liveTheme(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, function (): array {
            return ThemeCatalog::normalize($this->activeSchedule()->theme ?? $this->published());
        });
    }

    public function activeSchedule(): ?ThemeSnapshot
    {
        $now = now();

        return ThemeSnapshot::query()
            ->where('kind', ThemeSnapshot::SCHEDULE)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function published(): ?array
    {
        return SiteTheme::query()->find(1)?->theme;
    }

    /**
     * @return array{preset: string, colors: array<string, string>, font: string, radius: string, darkMode: bool, logoFavicon: bool}
     */
    public function draft(): array
    {
        $record = SiteTheme::query()->find(1);

        return ThemeCatalog::normalize($record->draft ?? $record?->theme);
    }

    public function version(): int
    {
        return SiteTheme::query()->find(1)->version ?? 0;
    }

    /**
     * @param  array<string, mixed>  $theme
     */
    public function saveDraft(array $theme, int $version): void
    {
        $this->mutate($version, function (SiteTheme $record) use ($theme): void {
            $record->draft = ThemeCatalog::normalize($theme);
        });
    }

    public function discardDraft(int $version): void
    {
        $this->mutate($version, function (SiteTheme $record): void {
            $record->draft = null;
        });
    }

    public function publish(int $version, User $user): void
    {
        $this->mutate($version, function (SiteTheme $record) use ($user): void {
            $published = ThemeCatalog::normalize($record->theme);
            $draft = ThemeCatalog::normalize($record->draft ?? $record->theme);

            if ($draft === $published) {
                return;
            }

            ThemeSnapshot::query()->create([
                'kind' => ThemeSnapshot::HISTORY,
                'theme' => $published,
                'user_id' => $user->id,
                'created_at' => now(),
            ]);
            $this->keepNewest(ThemeSnapshot::HISTORY, self::HISTORY_LIMIT);

            $record->theme = $draft;
            $record->draft = null;
            $this->log($user, 'published');
        });
    }

    /**
     * Loads a history entry or saved theme into the draft. It does not go live.
     */
    public function applySnapshot(ThemeSnapshot $snapshot, int $version): void
    {
        $this->mutate($version, function (SiteTheme $record) use ($snapshot): void {
            $record->draft = ThemeCatalog::normalize($snapshot->theme);
        });
    }

    public function saveNamed(string $name, User $user): void
    {
        DB::transaction(function () use ($name, $user): void {
            ThemeSnapshot::query()->create([
                'kind' => ThemeSnapshot::SAVED,
                'name' => $name,
                'theme' => $this->draft(),
                'user_id' => $user->id,
                'created_at' => now(),
            ]);
            $this->keepNewest(ThemeSnapshot::SAVED, self::SAVED_LIMIT);
        });
    }

    /**
     * @param  array<string, mixed>  $theme
     */
    public function schedule(string $name, array $theme, CarbonImmutable $startsAt, CarbonImmutable $endsAt, User $user): void
    {
        DB::transaction(function () use ($name, $theme, $startsAt, $endsAt, $user): void {
            $overlap = ThemeSnapshot::query()
                ->where('kind', ThemeSnapshot::SCHEDULE)
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $startsAt)
                ->lockForUpdate()
                ->exists();

            if ($overlap) {
                throw ValidationException::withMessages(['starts_at' => __('This period overlaps another scheduled theme.')]);
            }

            ThemeSnapshot::query()->create([
                'kind' => ThemeSnapshot::SCHEDULE,
                'name' => $name,
                'theme' => ThemeCatalog::normalize($theme),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'user_id' => $user->id,
                'created_at' => now(),
            ]);
            $this->log($user, 'scheduled');
        });

        Cache::forget(self::CACHE_KEY);
    }

    public function deleteSnapshot(ThemeSnapshot $snapshot): void
    {
        $snapshot->delete();

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @param  Closure(SiteTheme): void  $change
     */
    private function mutate(int $version, Closure $change): void
    {
        DB::transaction(function () use ($version, $change): void {
            SiteTheme::query()->firstOrCreate(['id' => 1], [
                'theme' => ThemeCatalog::defaultTheme(),
                'version' => 0,
            ]);

            $record = SiteTheme::query()->lockForUpdate()->findOrFail(1);

            if ($record->version !== $version) {
                throw ValidationException::withMessages([
                    'theme' => __('The theme was changed in another tab. Reload this page to continue.'),
                ]);
            }

            $change($record);
            $record->version++;
            $record->save();
        });

        Cache::forget(self::CACHE_KEY);
    }

    private function keepNewest(string $kind, int $limit): void
    {
        $stale = ThemeSnapshot::query()
            ->where('kind', $kind)
            ->orderByDesc('id')
            ->skip($limit)
            ->take(PHP_INT_MAX)
            ->pluck('id');

        ThemeSnapshot::query()->whereIn('id', $stale)->delete();
    }

    private function log(User $user, string $action): void
    {
        DB::table('store_admin_activities')->insert([
            'user_id' => $user->id,
            'section' => 'theme',
            'action' => $action,
            'record_id' => null,
            'changes' => json_encode(['fields' => ['theme']], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
