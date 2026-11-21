<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Offer;
use App\Models\Product;
use App\Rules\TurnstileToken;
use App\Services\ProductPricing;
use App\Support\BrandDirectory;
use App\Support\MovedUrls;
use App\Support\ProductCardData;
use App\Support\StorefrontCache;
use App\Support\StorefrontSeo;
use App\Support\StorePolicies;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class StorefrontController extends Controller
{
    /** Categories with fewer products than this are too thin for a home page row. */
    private const int RAIL_MINIMUM_PRODUCTS = 3;

    private const int RAIL_CATEGORIES = 6;

    /** The most sections the admin can put on the home page. */
    private const int PICKED_RAIL_CATEGORIES = 12;

    private const int RAIL_PRODUCTS = 12;

    public function index(string $locale): Response
    {
        return Inertia::render('storefront/home', [
            ...StorefrontCache::remember("home.{$locale}", fn (): array => $this->homeRows($locale)),
            'seo' => StorefrontSeo::home($locale),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function homeRows(string $locale): array
    {
        $categories = $this->visibleCategories($locale);
        $rails = $this->homeSections($locale, $categories);
        $categories = $categories->take(24)->values();

        // One query for every row: a main section's row holds its children's products too.
        $railProducts = Product::query()
            ->with($this->cardRelations($locale))
            ->where('is_active', true)
            ->whereIn('category_id', $rails->flatMap(fn (Category $rail): array => $rail->getAttribute('family'))->all())
            ->latest('id')
            ->get();

        foreach ($rails as $rail) {
            $rail->setRelation('products', $railProducts
                ->whereIn('category_id', $rail->getAttribute('family'))
                ->take(self::RAIL_PRODUCTS)
                ->values());
        }

        $brands = BrandDirectory::cards($locale);
        usort($brands, fn (array $a, array $b): int => ($b['logo_url'] !== null) <=> ($a['logo_url'] !== null));

        return [
            'brands' => array_slice($brands, 0, 16),
            'slides' => $this->slides($locale),
            'offers' => Offer::query()->running()
                ->orderByRaw('CASE WHEN ends_at IS NULL THEN 1 ELSE 0 END')
                ->orderBy('ends_at')
                ->latest('id')
                ->get()
                ->filter(fn (Offer $offer): bool => $offer->coversAnything())
                ->take(6)
                ->map(fn (Offer $offer): array => $this->offerCard($offer, $locale))
                ->filter(fn (array $offer): bool => $offer['title'] !== null)
                ->values()
                ->all(),
            // Plain arrays only: the cache refuses to rebuild objects (cache.serializable_classes).
            'categories' => $categories->map(fn (Category $category): array => $this->categoryCard($category))->values()->all(),
            'latest' => $this->cards(Product::query()->with($this->cardRelations($locale))
                ->where('is_active', true)
                ->latest('id')
                ->limit(self::RAIL_PRODUCTS)
                ->get(), $locale),
            // Biggest discounts first, then the newest.
            'deals' => $this->cards(Product::query()->with($this->cardRelations($locale))
                ->where('is_active', true)
                ->onSale()
                ->orderByRaw('(price - sale_price) / price desc')
                ->latest('id')
                ->limit(self::RAIL_PRODUCTS)
                ->get(), $locale),
            // "Feature on homepage" in the product form.
            'featured' => $this->cards(Product::query()->with($this->cardRelations($locale))
                ->where('is_active', true)
                ->where('is_featured', true)
                ->latest('id')
                ->limit(self::RAIL_PRODUCTS)
                ->get(), $locale),
            'rails' => $rails->map(fn (Category $category): array => [
                ...$this->categoryCard($category),
                'products' => $this->cards($category->products, $locale),
            ])->values()->all(),
            'services' => DB::table('services')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->limit(4)
                ->get(['title', 'description', 'icon'])
                ->map(fn (object $service): array => [
                    'title' => ProductCardData::localized($service->title, $locale),
                    'description' => ProductCardData::localized($service->description, $locale),
                    'icon' => $service->icon,
                ])
                ->filter(fn (array $service): bool => $service['title'] !== null)
                ->values()
                ->all(),
        ];
    }

    /**
     * The category sections on the home page: the ones the admin ticked "Show on
     * the home page" for, in their display order, or, when none is ticked, the
     * largest ones.
     *
     * @param  Collection<int, Category>  $visible  From visibleCategories().
     * @return Collection<int, Category>
     */
    private function homeSections(string $locale, Collection $visible): Collection
    {
        $picked = Category::query()
            ->where('is_active', true)
            ->where('show_on_home', true)
            ->withCount(['products' => fn ($query) => $query->where('is_active', true)])
            ->with(['translations' => fn ($query) => $query->where('locale', $locale)])
            ->orderBy('position')
            ->orderBy('id')
            ->limit(self::PICKED_RAIL_CATEGORIES)
            ->get();

        if ($picked->isEmpty()) {
            return $visible
                ->filter(fn (Category $category): bool => $category->products_count >= self::RAIL_MINIMUM_PRODUCTS)
                ->take(self::RAIL_CATEGORIES)
                ->values();
        }

        // A main section brings its children's products, as on its own page.
        return $picked
            ->map(fn (Category $category): Category => $visible->firstWhere('id', $category->id)
                ?? $category->setAttribute('family', $category->parent_id === null ? $category->familyIds() : [$category->id]))
            ->filter(fn (Category $category): bool => $category->translations->isNotEmpty())
            ->values();
    }

    public function categories(string $locale): Response
    {
        return Inertia::render('storefront/categories', [
            'categories' => $this->categoryCards($locale),
            'seo' => StorefrontSeo::categories($locale),
        ]);
    }

    public function category(Request $request, string $locale, string $slug): Response|RedirectResponse
    {
        $category = Category::query()
            ->where('is_active', true)
            ->whereHas('translations', fn ($query) => $query->where('locale', $locale)->where('slug', $slug))
            ->first();

        // A slug from another language (after switching language) moves to this one's.
        if ($category === null) {
            $moved = MovedUrls::category($locale, $slug);

            abort_if($moved === null, 404);

            return redirect()->to($moved, 301);
        }

        return $this->products($request, $locale, $slug, $category);
    }

    public function brands(string $locale): Response
    {
        return Inertia::render('storefront/brands', [
            'brands' => BrandDirectory::cards($locale),
            'seo' => StorefrontSeo::brands($locale),
        ]);
    }

    public function brandMenu(string $locale): JsonResponse
    {
        return response()->json(['brands' => BrandDirectory::cards($locale)])
            ->header('Cache-Control', 'public, max-age=300');
    }

    /**
     * A brand's own page: indexable, at a clean address, unlike ?brand= filters.
     */
    public function brand(Request $request, string $locale, string $slug): Response
    {
        $brand = Brand::query()->where('slug', $slug)->where('is_active', true)->firstOrFail();

        return $this->products($request, $locale, null, null, $brand);
    }

    /**
     * A running offer's own page: what it covers, at the offer price.
     */
    public function offer(Request $request, string $locale, int $offer): Response
    {
        $running = Offer::query()->running()->findOrFail($offer);
        abort_unless($running->coversAnything(), 404);

        return $this->products($request, $locale, null, null, null, $running);
    }

    public function products(Request $request, string $locale, ?string $categorySlug = null, ?Category $category = null, ?Brand $brand = null, ?Offer $offer = null): Response
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:180'],
            'sort' => ['nullable', 'string', 'in:newest,price_asc,price_desc'],
            'price_min' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'price_max' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'brand' => ['nullable', 'string', 'max:120', 'alpha_dash'],
            'available' => ['nullable', 'boolean'],
            'sale' => ['nullable', 'boolean'],
            'featured' => ['nullable', 'boolean'],
        ]);
        $search = trim($validated['search'] ?? '');
        $sort = $validated['sort'] ?? 'newest';
        // /products?category=x shows the same list as the category's own page.
        $filteredByQuery = $categorySlug === null && filled($validated['category'] ?? null);
        $categorySlug ??= $validated['category'] ?? null;
        // Price, brand and stock filters make endless near-copies of a page: not for search engines.
        $narrowed = isset($validated['price_min']) || isset($validated['price_max']) || filled($validated['brand'] ?? null) || (bool) ($validated['available'] ?? false) || (bool) ($validated['sale'] ?? false) || (bool) ($validated['featured'] ?? false);

        $products = Product::query()->with($this->cardRelations($locale))
            ->where('is_active', true)
            ->when($search !== '', fn ($query) => $query->search($search))
            ->when($categorySlug, fn ($query) => $query->whereIn('category_id', $this->categoryFamily($locale, $categorySlug, $category)))
            // Price filters and sorting follow the price paid, after any discount.
            ->when(isset($validated['price_min']), fn ($query) => $query->wherePricePaid('>=', $validated['price_min']))
            ->when(isset($validated['price_max']), fn ($query) => $query->wherePricePaid('<=', $validated['price_max']))
            ->when((bool) ($validated['sale'] ?? false), fn ($query) => $query->onSale())
            // The home page's "Featured products", in full.
            ->when((bool) ($validated['featured'] ?? false), fn ($query) => $query->where('is_featured', true))
            ->when($brand !== null, fn ($query) => $query->where('brand_id', $brand?->id))
            ->when($offer !== null, fn ($query) => ProductPricing::restrictToOffer($query, $offer))
            ->when($brand === null && filled($validated['brand'] ?? null), fn ($query) => $query->whereHas('brand', fn ($query) => $query->where('slug', $validated['brand'])))
            // Orderable now: in stock, or imported with availability still to confirm.
            ->when((bool) ($validated['available'] ?? false), fn ($query) => $query->where(fn ($stock) => $stock
                ->where('stock_quantity', '>', 0)
                ->orWhere('price_is_estimate', true)))
            ->when($sort === 'price_asc', fn ($query) => $query->orderByPricePaid('asc'))
            ->when($sort === 'price_desc', fn ($query) => $query->orderByPricePaid('desc'))
            ->latest('id')
            ->paginate(24)
            ->withQueryString()
            ->through(fn (Product $product): array => ProductCardData::from($product, $locale));

        return Inertia::render('storefront/index', [
            'products' => $products,
            'categories' => $this->categoryCards($locale),
            'search' => $search,
            'sort' => $sort,
            'selectedCategory' => $categorySlug,
            // On a main section: its categories, instead of every main section.
            'subcategories' => $category === null ? [] : array_values($category->children()
                ->where('is_active', true)
                ->whereHas('products', fn ($query) => $query->where('is_active', true))
                ->withCount(['products' => fn ($query) => $query->where('is_active', true)])
                ->with(['translations' => fn ($query) => $query->where('locale', $locale)])
                ->orderBy('position')
                ->get()
                ->filter(fn (Category $child): bool => $child->translations->isNotEmpty())
                ->map(fn (Category $child): array => $this->categoryCard($child))
                ->all()),
            'adminEdit' => match (true) {
                $category !== null => $this->adminEdit('category', "/admin/categories/{$category->id}/edit"),
                $brand !== null => $this->adminEdit('brand', "/admin/modules/brands/{$brand->id}/edit"),
                default => null,
            },
            'parentCategory' => $category?->parent_id === null ? null : (function () use ($category, $locale): ?array {
                $translation = $category->parent?->translations()->where('locale', $locale)->first();

                return $translation === null ? null : ['name' => $translation->name, 'slug' => $translation->slug];
            })(),
            'offerPage' => $offer === null ? null : $this->offerCard($offer, $locale),
            'brandPage' => $brand === null ? null : [
                'slug' => $brand->slug,
                'name' => BrandDirectory::name($brand, $locale),
                'description' => BrandDirectory::description($brand, $locale),
                'logo_url' => filled($brand->logo_url) ? BrandDirectory::url((string) $brand->logo_url) : null,
            ],
            'filters' => [
                'price_min' => $validated['price_min'] ?? null,
                'price_max' => $validated['price_max'] ?? null,
                'brand' => $validated['brand'] ?? null,
                'available' => (bool) ($validated['available'] ?? false),
                'sale' => (bool) ($validated['sale'] ?? false),
                'featured' => (bool) ($validated['featured'] ?? false),
            ],
            'brands' => StorefrontCache::remember("brands.{$locale}", fn (): array => array_values(DB::table('brands')
                ->where('is_active', true)
                ->whereExists(fn ($query) => $query->selectRaw('1')->from('products')->whereColumn('products.brand_id', 'brands.id')->where('products.is_active', true))
                ->orderBy('sort_order')
                ->get(['slug', 'name'])
                ->map(fn (object $brand): array => ['slug' => (string) $brand->slug, 'name' => ProductCardData::localized($brand->name, $locale) ?? (string) $brand->slug])
                ->all())),
            'seo' => match (true) {
                $brand !== null => StorefrontSeo::brand($locale, $brand, $products->currentPage(), $search, $narrowed),
                $category !== null => StorefrontSeo::category($locale, $category, $products->currentPage(), $search, $narrowed),
                // An offer page lasts days: kept out of search results.
                default => StorefrontSeo::products($locale, $search, $products->currentPage(), $filteredByQuery || $narrowed || $offer !== null),
            },
        ]);
    }

    /**
     * Favorites live in the browser (localStorage), so they work without an
     * account. The page asks favoriteProducts() for the cards. It has no SEO
     * data, so it is kept out of the index.
     */
    public function favorites(string $locale): Response
    {
        return Inertia::render('storefront/favorites');
    }

    /**
     * Cards for the saved ids that are still for sale, in the order saved.
     */
    public function favoriteProducts(Request $request, string $locale): JsonResponse
    {
        $ids = collect(explode(',', (string) $request->query('ids')))
            ->map(fn (string $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->take(60)
            ->values();

        $position = array_flip($ids->all());

        $products = Product::query()
            ->with($this->cardRelations($locale))
            ->where('is_active', true)
            ->whereKey($ids)
            ->get()
            ->sortBy(fn (Product $product): int => $position[$product->id] ?? PHP_INT_MAX);

        return response()->json(['products' => $this->cards($products, $locale)]);
    }

    /**
     * The comparison list lives in the browser, like favorites, so the page
     * asks compareProducts() for the details. It has no SEO data: noindex.
     */
    public function compare(string $locale): Response
    {
        return Inertia::render('storefront/compare');
    }

    /**
     * Up to four products still for sale, with what the comparison table shows.
     */
    public function compareProducts(Request $request, string $locale): JsonResponse
    {
        $ids = collect(explode(',', (string) $request->query('ids')))
            ->map(fn (string $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->take(4)
            ->values();

        $position = array_flip($ids->all());

        $ratings = DB::table('reviews')
            ->whereIn('product_id', $ids)
            ->where('is_approved', true)
            ->selectRaw('product_id, COUNT(*) as total, AVG(rating) as average')
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        $products = Product::query()
            ->with($this->cardRelations($locale))
            ->where('is_active', true)
            ->whereKey($ids)
            ->get()
            ->sortBy(fn (Product $product): int => $position[$product->id] ?? PHP_INT_MAX)
            ->map(function (Product $product) use ($locale, $ratings): array {
                $rating = $ratings->get($product->id);
                $description = $product->translations->first()?->description;

                return [
                    ...ProductCardData::from($product, $locale),
                    'sku' => $product->sku,
                    'summary' => $description ? Str::limit(trim(strip_tags($description)), 220) : null,
                    'rating' => ['average' => round((float) ($rating->average ?? 0), 1), 'count' => (int) ($rating->total ?? 0)],
                ];
            })
            ->values();

        return response()->json(['products' => $products]);
    }

    /**
     * Live suggestions under the search box.
     */
    public function suggest(Request $request, string $locale): JsonResponse
    {
        $search = trim((string) $request->query('q'));

        if (mb_strlen($search) < 2) {
            return response()->json(['products' => []]);
        }

        $products = Product::query()
            ->with($this->cardRelations($locale))
            ->where('is_active', true)
            ->search(mb_substr($search, 0, 100))
            ->latest('id')
            ->limit(6)
            ->get();

        return response()->json(['products' => $this->cards($products, $locale)]);
    }

    public function show(string $locale, string $slug): Response|RedirectResponse
    {
        $product = Product::query()->with($this->cardRelations($locale))
            ->where('is_active', true)
            ->whereHas('translations', fn ($query) => $query->where('locale', $locale)->where('slug', $slug))
            ->first();

        // A slug from another language (after switching language) moves to this one's.
        if ($product === null) {
            $moved = MovedUrls::product($locale, $slug);

            abort_if($moved === null, 404);

            return redirect()->to($moved, 301);
        }

        $translation = $product->translations->firstOrFail();

        $related = Product::query()->with($this->cardRelations($locale))
            ->where('is_active', true)
            ->whereKeyNot($product->id)
            ->where(fn (Builder $query) => $query
                ->where('category_id', $product->category_id)
                ->orWhere('brand_id', $product->brand_id))
            ->orderByRaw('CASE WHEN category_id = ? THEN 0 ELSE 1 END', [$product->category_id])
            ->latest('id')
            ->limit(self::RAIL_PRODUCTS)
            ->get();

        $reviews = DB::table('reviews')->where('product_id', $product->id)->where('is_approved', true);
        $rating = (clone $reviews)->selectRaw('COUNT(*) as total, AVG(rating) as average')->first();
        $user = request()->user();

        return Inertia::render('storefront/show', [
            'reviews' => [
                'count' => (int) ($rating->total ?? 0),
                'average' => round((float) ($rating->average ?? 0), 1),
                'items' => (clone $reviews)->latest('id')->limit(10)->get(['customer_name', 'rating', 'comment', 'created_at'])
                    ->map(fn (object $review): array => [
                        // First name only: reviews are public.
                        'name' => (string) str((string) $review->customer_name)->before(' '),
                        'rating' => (int) $review->rating,
                        'comment' => $review->comment,
                        'date' => substr((string) $review->created_at, 0, 10),
                    ])->values(),
                'can_review' => $user !== null && CustomerFeaturesController::hasBought($user->id, $product->id),
                'mine' => $user === null ? null : DB::table('reviews')->where('product_id', $product->id)->where('user_id', $user->id)
                    ->first(['rating', 'comment', 'is_approved']),
            ],
            'notifyWhenAvailable' => $product->is_active && ! $product->isPurchasable(),
            'policies' => StorePolicies::for($locale),
            'turnstileSiteKey' => TurnstileToken::siteKey(),
            'adminEdit' => $this->adminEdit('product', "/admin/products/{$product->id}/edit"),
            'product' => [
                ...ProductCardData::from($product, $locale),
                'sku' => $product->sku,
                'images' => $product->images
                    ->map(fn ($image) => Storage::disk('public')->url($image->path))
                    ->values(),
                'description' => $translation->description,
                'seo_title' => $translation->seo_title,
                'seo_description' => $translation->seo_description,
                'category_slug' => $product->category?->translations->first()?->slug,
                // The offer that gives the sale price, with a link to all it covers.
                'offer' => $product->hasActiveDiscount() && $product->offer !== null
                    ? $this->offerCard($product->offer, $locale)
                    : null,
            ],
            'related' => $this->cards($related, $locale),
            'seo' => StorefrontSeo::product($locale, $product, (int) ($rating->total ?? 0), (float) ($rating->average ?? 0)),
        ]);
    }

    public function page(string $locale, string $slug): Response
    {
        $page = DB::table('pages')->where('slug', $slug)->where('is_active', true)->first();

        abort_unless($page !== null, 404);

        $title = ProductCardData::localized($page->title, $locale);
        $content = ProductCardData::localized($page->content, $locale);

        return Inertia::render('storefront/page', [
            'page' => [
                'title' => $title,
                'content' => $content,
            ],
            'seo' => StorefrontSeo::contentPage($locale, $slug, (string) $title, $content),
        ]);
    }

    /**
     * @return list<array{name: string|null, slug: string|null, image_url: string|null, count: int}>
     */
    private function categoryCards(string $locale): array
    {
        return StorefrontCache::remember("categories.{$locale}", fn (): array => array_values($this->visibleCategories($locale)
            ->map(fn (Category $category): array => $this->categoryCard($category))
            ->all()));
    }

    /**
     * Where staff who manage the catalog edit what this page shows, for the
     * staff bar above the store (StaffBar). Nobody else gets it.
     *
     * @return array{type: string, url: string}|null
     */
    private function adminEdit(string $type, string $url): ?array
    {
        return request()->user()?->role->canManageCatalog() ? ['type' => $type, 'url' => $url] : null;
    }

    /**
     * The category ids a listing shows: the category and its active children.
     * An unknown slug lists nothing.
     *
     * @return list<int>
     */
    private function categoryFamily(string $locale, string $slug, ?Category $category): array
    {
        $category ??= Category::query()
            ->where('is_active', true)
            ->whereHas('translations', fn ($query) => $query->where('locale', $locale)->where('slug', $slug))
            ->first();

        return $category === null ? [0] : $category->familyIds();
    }

    /**
     * Categories with something to show, richest first.
     *
     * @return Collection<int, Category>
     */
    private function visibleCategories(string $locale, ?int $limit = null): Collection
    {
        $all = Category::query()
            ->where('is_active', true)
            ->withCount(['products' => fn ($query) => $query->where('is_active', true)])
            ->with(['translations' => fn ($query) => $query->where('locale', $locale)])
            ->get();

        $children = $all->whereNotNull('parent_id')->groupBy('parent_id');
        $tops = $all->keyBy('id');

        // Main sections, counting their children's products. A category whose
        // main section is hidden is shown on its own.
        return $all
            ->filter(fn (Category $category): bool => $category->parent_id === null || ! $tops->has($category->parent_id))
            ->map(function (Category $category) use ($children): Category {
                $kids = $category->parent_id === null ? $children->get($category->id, collect()) : collect();
                $category->setAttribute('products_count', (int) $category->products_count + (int) $kids->sum('products_count'));
                $category->setAttribute('family', [$category->id, ...$kids->pluck('id')->all()]);

                return $category;
            })
            ->filter(fn (Category $category): bool => $category->products_count > 0 && $category->translations->isNotEmpty())
            ->sortBy([['products_count', 'desc'], ['position', 'asc']])
            ->when($limit, fn ($categories) => $categories->take($limit))
            ->values();
    }

    /**
     * @return array{name: string|null, slug: string|null, image_url: string|null, count: int}
     */
    private function categoryCard(Category $category): array
    {
        $translation = $category->translations->first();

        return [
            'name' => $translation?->name,
            'slug' => $translation?->slug,
            'image_url' => ($category->thumb_path ?? $category->image_path) ? Storage::disk('public')->url((string) ($category->thumb_path ?? $category->image_path)) : null,
            'count' => (int) $category->products_count,
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    private function cardRelations(string $locale): array
    {
        return [
            ...ProductCardData::LOAD,
            'translations' => fn ($translations) => $translations->where('locale', $locale),
            'category.translations' => fn ($translations) => $translations->where('locale', $locale),
        ];
    }

    /**
     * @param  iterable<int, Product>  $products
     * @return array<int, array<string, mixed>>
     */
    private function cards(iterable $products, string $locale): array
    {
        return collect($products)
            ->map(fn (Product $product): array => ProductCardData::from($product, $locale))
            ->filter(fn (array $card): bool => $card['slug'] !== null)
            ->values()
            ->all();
    }

    /**
     * @return array{id: int, title: string|null, description: string|null, image_url: string|null, ends_at: string|null, discount_type: string, discount_value: string}
     */
    private function offerCard(Offer $offer, string $locale): array
    {
        return [
            'id' => $offer->id,
            'title' => $offer->titleIn($locale),
            'description' => $offer->descriptionIn($locale),
            'image_url' => $offer->image_url,
            'ends_at' => $offer->ends_at?->toIso8601String(),
            'discount_type' => $offer->discount_type,
            'discount_value' => (string) $offer->discount_value,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function slides(string $locale): array
    {
        return DB::table('hero_slides')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->limit(5)
            ->get(['image_url', 'title', 'subtitle', 'link_url'])
            ->map(fn (object $slide): array => [
                'image_url' => $slide->image_url,
                'title' => ProductCardData::localized($slide->title, $locale),
                'subtitle' => ProductCardData::localized($slide->subtitle, $locale),
                'link_url' => $slide->link_url,
            ])
            ->values()
            ->all();
    }
}
