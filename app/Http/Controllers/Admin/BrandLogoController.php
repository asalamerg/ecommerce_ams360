<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Services\ImageUploads;
use App\Support\BrandDirectory;
use App\Support\StorefrontCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every brand's logo on one page: missing ones first, dropped straight onto
 * the brand's card. Uploads are re-encoded and their empty margin trimmed, so
 * logos look the same size on the storefront.
 */
class BrandLogoController extends Controller
{
    private const int LOGO_SIDE = 600;

    public function index(): Response
    {
        $locale = app()->getLocale();

        $brands = Brand::query()
            ->withCount(['products' => fn ($query) => $query->where('is_active', true)])
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Brand $brand): array => [
                'id' => $brand->id,
                'name' => BrandDirectory::name($brand, $locale),
                'slug' => $brand->slug,
                'logo_url' => filled($brand->logo_url) ? BrandDirectory::url((string) $brand->logo_url) : null,
                'products' => (int) $brand->products_count,
                'is_active' => $brand->is_active,
            ])
            // Missing logos first, busiest brands first within each group.
            ->sortBy([fn (array $a, array $b): int => ($a['logo_url'] !== null) <=> ($b['logo_url'] !== null), fn (array $a, array $b): int => $b['products'] <=> $a['products']])
            ->values();

        return Inertia::render('admin/brands/logos', [
            'brands' => $brands,
            'withLogo' => $brands->whereNotNull('logo_url')->count(),
        ]);
    }

    public function store(Request $request, Brand $brand, ImageUploads $uploads): RedirectResponse
    {
        $request->validate(['logo' => ['required', ...ImageUploads::rules()]]);

        DB::transaction(function () use ($request, $brand, $uploads): void {
            $path = $uploads->store($request->file('logo'), 'uploads/brands', self::LOGO_SIDE, 'logo', trim: true);
            $this->replace($brand, Storage::disk(ImageUploads::DISK)->url($path), $uploads, 'logo_uploaded');
        });

        return back()->with('success', __('Logo saved for :brand.', ['brand' => BrandDirectory::name($brand, app()->getLocale())]));
    }

    public function destroy(Brand $brand, ImageUploads $uploads): RedirectResponse
    {
        DB::transaction(fn () => $this->replace($brand, null, $uploads, 'logo_removed'));

        return back()->with('success', __('Logo removed.'));
    }

    /**
     * Saves the new logo, records who changed it, and deletes the old upload
     * once committed unless another brand still shows it. Links to other
     * sites are never deleted.
     */
    private function replace(Brand $brand, ?string $url, ImageUploads $uploads, string $action): void
    {
        $old = $brand->logo_url;

        DB::table('brands')->where('id', $brand->id)->update(['logo_url' => $url, 'updated_at' => now()]);

        DB::table('store_admin_activities')->insert([
            'user_id' => request()->user()?->id,
            'section' => 'brands',
            'action' => $action,
            'record_id' => $brand->id,
            'changes' => json_encode(['logo_url' => [$old, $url]], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        if (is_string($old) && $old !== $url && str_starts_with($old, '/storage/uploads/brands/')
            && ! DB::table('brands')->where('logo_url', $old)->exists()) {
            $uploads->deleteAfterCommit(substr($old, strlen('/storage/')));
        }

        StorefrontCache::flush();
    }
}
