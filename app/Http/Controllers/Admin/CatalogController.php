<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Inventory\AdjustProductStock;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\StoreProductRequest;
use App\Models\AuthEvent;
use App\Models\Brand;
use App\Models\CatalogActivity;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductColor;
use App\Models\ProductImage;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\ImageUploads;
use App\Services\ProductImageStore;
use App\Services\ProductPricing;
use App\Services\ProductSpreadsheet;
use App\Support\AdminReturnUrl;
use App\Support\SearchText;
use App\Support\Slugs;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CatalogController extends Controller
{
    /** Category images show as circles and cards, never larger than this. */
    private const int CATEGORY_IMAGE_SIDE = 800;

    /** Quick filters above the product list. */
    private const array PRODUCT_FILTERS = ['all', 'shown', 'hidden', 'featured', 'low', 'out', 'estimated', 'on_sale'];

    /** Stock at or below this shows under "Low stock". */
    private const int LOW_STOCK = 3;

    public function products(Request $request): Response
    {
        $this->authorize('viewAny', Product::class);

        $search = trim((string) $request->query('q'));
        $status = in_array($request->query('status'), self::PRODUCT_FILTERS, true)
            ? (string) $request->query('status')
            : 'all';

        $products = Product::query()
            ->with(['images', 'translations', 'category.translations', 'offer'])
            ->where(fn (Builder $query) => $this->filterProducts($query, $status))
            // The storefront search: all three languages, brand and SKU, with
            // Arabic spelling variants (ة/ه, أ/ا, ى/ي) treated as the same letter.
            ->when($search !== '', fn (Builder $query) => $query->search(mb_substr($search, 0, 100)))
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Product $product) => [
                'id' => $product->id,
                'sku' => $product->sku,
                'price' => $product->price,
                'sale_price' => $product->hasActiveDiscount() ? $product->salePrice() : null,
                'discount_percent' => $product->discountShown(),
                // An offer, rather than the product's own discount, gives the price.
                'offer' => $product->hasActiveDiscount() && $product->offer_id !== null
                    ? $product->offer?->titleIn(app()->getLocale())
                    : null,
                'currency' => $product->currency,
                'stock_quantity' => $product->stock_quantity,
                'is_active' => $product->is_active,
                'is_featured' => $product->is_featured,
                'price_is_estimate' => $product->price_is_estimate,
                'image_url' => $product->images->first()
                    ? Storage::disk('public')->url($product->images->first()->path)
                    : null,
                'translations' => $product->translations->keyBy('locale'),
                'category' => $product->category?->translations->keyBy('locale'),
            ]);

        return Inertia::render('admin/products/index', [
            'products' => $products,
            'search' => $search,
            'status' => $status,
            'counts' => collect(self::PRODUCT_FILTERS)
                ->mapWithKeys(fn (string $filter): array => [
                    $filter => Product::query()->where(fn (Builder $query) => $this->filterProducts($query, $filter))->count(),
                ])
                ->all(),
        ]);
    }

    /**
     * One change for many products: show, hide, confirm their prices, or set or
     * remove a discount.
     */
    public function bulkProducts(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'distinct'],
            'action' => ['required', 'in:show,hide,confirm_price,discount,remove_discount,feature,unfeature'],
            'discount_percent' => ['exclude_unless:action,discount', 'required', 'integer', 'min:1', 'max:'.Product::MAX_DISCOUNT_PERCENT],
            'discount_ends_at' => ['exclude_unless:action,discount', 'nullable', 'date', 'after_or_equal:today'],
        ], attributes: [
            'discount_percent' => __('Discount (%)'),
            'discount_ends_at' => __('Discount ends on'),
        ]);

        $changes = match ($data['action']) {
            'show' => ['is_active' => true],
            'hide' => ['is_active' => false],
            'confirm_price' => ['price_is_estimate' => false],
            'discount' => $this->discountFields($data),
            'remove_discount' => ['discount_percent' => 0, 'discount_ends_at' => null],
            'feature' => ['is_featured' => true],
            'unfeature' => ['is_featured' => false],
            default => throw ValidationException::withMessages(['action' => __('validation.in', ['attribute' => 'action'])]),
        };
        $updated = 0;

        DB::transaction(function () use ($data, $changes, &$updated): void {
            foreach (Product::query()->whereKey($data['ids'])->get() as $product) {
                $this->authorize('update', $product);
                $before = $product->only(array_keys($changes));
                $product->update($changes);

                if ($product->wasChanged()) {
                    $updated++;
                    $this->recordCatalogActivity("product.bulk_{$data['action']}", $product, $before, $product->only(array_keys($changes)));
                }
            }
        });

        return back()->with('success', __(':count products updated.', ['count' => $updated]));
    }

    public function exportProducts(ProductSpreadsheet $spreadsheet): StreamedResponse
    {
        $this->authorize('viewAny', Product::class);

        return $spreadsheet->export();
    }

    public function importProducts(Request $request, ProductSpreadsheet $spreadsheet): RedirectResponse
    {
        $this->authorize('create', Product::class);

        $data = $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);
        $result = $spreadsheet->import($data['file'], $request->user());

        if ($result['errors'] !== []) {
            return back()->withErrors(['file' => implode("\n", $result['errors'])]);
        }

        return back()->with('success', __(':count products updated from the file.', ['count' => $result['updated']]));
    }

    public function activity(Request $request): Response
    {
        $this->authorize('viewAny', Product::class);

        return Inertia::render('admin/activity/index', [
            'authEvents' => $request->user()->role->canManageAdministration()
                ? AuthEvent::query()
                    ->with('user:id,name,email')
                    ->latest('created_at')
                    ->limit(40)
                    ->get()
                    ->map(fn (AuthEvent $event) => [
                        'id' => $event->id,
                        'event' => $event->event,
                        'actor' => $event->user?->email,
                        'ip_address' => $event->ip_address,
                        'created_at' => $event->created_at->toIso8601String(),
                    ])
                : [],
            'activities' => CatalogActivity::query()
                ->with('user:id,name')
                ->latest('created_at')
                ->paginate(30)
                ->withQueryString()
                ->through(fn (CatalogActivity $activity) => [
                    'id' => $activity->id,
                    'actor' => $activity->user_id === null ? __('Deleted user') : $activity->user->name,
                    'action' => $activity->action,
                    'subject_type' => class_basename($activity->subject_type),
                    'subject_id' => $activity->subject_id,
                    'changes' => $activity->changes,
                    'created_at' => CarbonImmutable::parse($activity->created_at)->toIso8601String(),
                ]),
            'storeActivities' => DB::table('store_admin_activities')
                ->leftJoin('users', 'users.id', '=', 'store_admin_activities.user_id')
                ->latest('store_admin_activities.created_at')
                ->limit(40)
                ->get([
                    'store_admin_activities.id',
                    'users.name as actor',
                    'store_admin_activities.section',
                    'store_admin_activities.action',
                    'store_admin_activities.record_id',
                    'store_admin_activities.changes',
                    'store_admin_activities.created_at',
                ])
                ->map(function (object $activity): array {
                    $changes = json_decode((string) $activity->changes, true);

                    return [
                        'id' => (int) $activity->id,
                        'actor' => $activity->actor ?? __('Deleted user'),
                        'section' => $activity->section,
                        'action' => $activity->action,
                        'record_id' => $activity->record_id === null ? null : (int) $activity->record_id,
                        'changes' => is_array($changes) ? $changes : [],
                        'created_at' => CarbonImmutable::parse($activity->created_at)->toIso8601String(),
                    ];
                }),
        ]);
    }

    public function createProduct(Request $request): Response
    {
        $this->authorize('create', Product::class);

        return Inertia::render('admin/products/form', [
            'product' => null,
            // "Save and add another" keeps the category and brand of the last one.
            'defaults' => [
                'category_id' => $request->integer('category_id') ?: null,
                'brand_id' => $request->integer('brand_id') ?: null,
            ],
            'back' => AdminReturnUrl::for('/admin/products', $request->input('back')),
            'categories' => $this->categoryOptions(),
            'brands' => $this->brandOptions(),
        ]);
    }

    public function storeProduct(StoreProductRequest $request, ProductImageStore $images, AdjustProductStock $stock): RedirectResponse
    {
        $this->authorize('create', Product::class);

        $data = $request->validated();
        $data['translations'] = Slugs::fill($data['translations'], 'product_translations', 'product_id', null, 'product');
        $product = DB::transaction(function () use ($data, $images, $stock, $request): Product {
            $product = Product::create([
                'category_id' => $data['category_id'] ?? null,
                'brand_id' => $data['brand_id'] ?? null,
                'sku' => $data['sku'] ?? null,
                'aliases' => $data['aliases'] ?? null,
                'price' => $data['price'],
                ...$this->discountFields($data),
                'currency' => $data['currency'],
                'stock_quantity' => 0,
                'is_active' => $data['is_active'],
                'is_featured' => $data['is_featured'],
                'price_is_estimate' => $data['price_is_estimate'],
            ]);

            foreach ($data['translations'] as $locale => $translation) {
                $product->translations()->create([
                    'locale' => $locale,
                    ...$translation,
                ]);
            }

            $this->syncImages($product, $data, $images);
            $this->syncColors($product, $data);
            $this->applyStock($product, $data, $request, $stock, 'Initial stock (product form)');
            $product->refresh()->load('translations');
            $this->recordCatalogActivity('product.created', $product, null, $this->productSnapshot($product));

            return $product;
        });

        return $this->afterSave($request, '/admin/products', $product, __('Product ":name" added.', ['name' => $this->productName($product)]), [
            'category_id' => $product->category_id,
            'brand_id' => $product->brand_id,
        ]);
    }

    public function editProduct(Request $request, Product $product): Response
    {
        $this->authorize('update', $product);

        return Inertia::render('admin/products/form', [
            'back' => AdminReturnUrl::for('/admin/products', $request->input('back')),
            'product' => [
                ...$product->only(['id', 'category_id', 'brand_id', 'sku', 'aliases', 'price', 'currency', 'stock_quantity', 'is_active', 'is_featured', 'price_is_estimate']),
                // An ended discount opens as none, so saving the form clears it.
                'discount_percent' => $product->ownDiscountActive() ? $product->discount_percent : 0,
                // As the date field shows it: a discount ends at the end of that day.
                'discount_ends_at' => $product->ownDiscountActive() ? $product->discount_ends_at?->toDateString() : null,
                // A running offer that gives a lower price than the own discount.
                'running_offer' => $product->hasActiveDiscount() && $product->offer_id !== null ? [
                    'title' => $product->offer?->titleIn(app()->getLocale()),
                    'sale_price' => $product->salePrice(),
                ] : null,
                'translations' => $product->translations()->get()->keyBy('locale'),
                'images' => $product->images->map(fn (ProductImage $image): array => [
                    'id' => $image->id,
                    'url' => Storage::disk('public')->url($image->path),
                ])->values(),
                'colors' => $product->colors->map(fn (ProductColor $color): array => [
                    'id' => $color->id,
                    'hex' => $color->hex,
                    'name' => $color->name,
                ])->values(),
            ],
            'categories' => $this->categoryOptions(),
            'brands' => $this->brandOptions(),
        ]);
    }

    public function updateProduct(StoreProductRequest $request, Product $product, ProductImageStore $images, AdjustProductStock $stock): RedirectResponse
    {
        $this->authorize('update', $product);

        $data = $request->validated();
        $data['translations'] = Slugs::fill($data['translations'], 'product_translations', 'product_id', $product->id, 'product');
        DB::transaction(function () use ($data, $product, $images, $stock, $request): void {
            $before = $this->productSnapshot($product);
            $product->update([
                'category_id' => $data['category_id'] ?? null,
                'brand_id' => $data['brand_id'] ?? null,
                'sku' => $data['sku'] ?? null,
                'aliases' => $data['aliases'] ?? null,
                'price' => $data['price'],
                ...$this->discountFields($data),
                'currency' => $data['currency'],
                'is_active' => $data['is_active'],
                'is_featured' => $data['is_featured'],
                'price_is_estimate' => $data['price_is_estimate'],
            ]);

            foreach ($data['translations'] as $locale => $translation) {
                $product->translations()->updateOrCreate(
                    ['locale' => $locale],
                    $translation,
                );
            }

            $this->syncImages($product, $data, $images);
            $this->syncColors($product, $data);
            $this->applyStock($product, $data, $request, $stock, 'Set from product form');
            $product->refresh()->load('translations');
            $this->recordCatalogActivity('product.updated', $product, $before, $this->productSnapshot($product));
        });

        return $this->afterSave($request, '/admin/products', $product, __('Product ":name" saved.', ['name' => $this->productName($product)]));
    }

    /**
     * A discount end date from the form is a day: the discount runs to its end.
     * No percentage means no discount, so a leftover date is cleared with it.
     *
     * @param  array<string, mixed>  $data
     * @return array{discount_percent: int, discount_ends_at: CarbonImmutable|null}
     */
    private function discountFields(array $data): array
    {
        $percent = (int) ($data['discount_percent'] ?? 0);

        return [
            'discount_percent' => $percent,
            'discount_ends_at' => $percent > 0 && filled($data['discount_ends_at'] ?? null)
                ? CarbonImmutable::parse($data['discount_ends_at'])->endOfDay()
                : null,
        ];
    }

    /**
     * Out of stock leaves out imported products whose stock was never entered:
     * they are still for sale while availability is confirmed by phone.
     *
     * @param  Builder<Product>  $query
     */
    private function filterProducts(Builder $query, string $status): void
    {
        match ($status) {
            'shown' => $query->where('is_active', true),
            'hidden' => $query->where('is_active', false),
            'low' => $query->whereBetween('stock_quantity', [1, self::LOW_STOCK]),
            'out' => $query->where('stock_quantity', 0)->where('price_is_estimate', false),
            // Imported prices nobody has checked yet: confirm them before selling.
            'estimated' => $query->where('price_is_estimate', true),
            'on_sale' => $query->onSale(),
            // "Featured products" on the home page.
            'featured' => $query->where('is_featured', true),
            default => null,
        };
    }

    /**
     * Shown or hidden in the store, from the list in one click. Same as the
     * "Publish product" box in the form.
     */
    public function toggleProductVisibility(Product $product): RedirectResponse
    {
        $this->authorize('update', $product);

        DB::transaction(function () use ($product): void {
            $before = $this->productSnapshot($product);
            $product->update(['is_active' => ! $product->is_active]);
            $this->recordCatalogActivity($product->is_active ? 'product.shown' : 'product.hidden', $product, $before, $this->productSnapshot($product));
        });

        return back()->with('success', __($product->is_active ? 'The product is shown in the store.' : 'The product is hidden from the store.'));
    }

    /**
     * Deletes the product for good. Orders keep their own copy of its name and
     * price, the stock history keeps its name, and the activity log keeps a full
     * snapshot of what was deleted. Its images are removed after the commit.
     */
    public function deleteProduct(Product $product, ImageUploads $uploads): RedirectResponse
    {
        $this->authorize('delete', $product);

        DB::transaction(function () use ($product, $uploads): void {
            $product->load('translations');
            $before = $this->productSnapshot($product);
            $name = $product->translations->pluck('name', 'locale');
            $images = $product->images()->get(['path', 'thumb_path']);

            StockMovement::query()->where('product_id', $product->id)->update([
                'product_name' => $name->get('ar') ?? $name->get('en') ?? $name->first(),
            ]);

            $product->forceDelete();
            $this->recordCatalogActivity('product.deleted', $product, $before, ['deleted' => true]);

            foreach ($images as $image) {
                if (! ProductImage::query()->where('path', $image->path)->exists()) {
                    $uploads->deleteAfterCommit($image->path);
                    $uploads->deleteAfterCommit($image->thumb_path);
                }
            }
        });

        return to_route('admin.products.index')->with('success', __('Product deleted permanently.'));
    }

    public function categories(Request $request): Response
    {
        $this->authorize('viewAny', Category::class);

        $search = trim((string) $request->query('q'));

        return Inertia::render('admin/categories/index', [
            'search' => $search,
            'categories' => Category::query()
                ->with(['translations', 'parent.translations'])
                ->withCount('children')
                ->when($search !== '', fn (Builder $query) => $query->whereKey($this->matchingCategoryIds(mb_substr($search, 0, 100)) ?: [0]))
                ->orderBy('position')
                ->paginate(20)
                ->withQueryString()
                ->through(fn (Category $category) => [
                    'id' => $category->id,
                    'is_active' => $category->is_active,
                    'show_on_home' => $category->show_on_home,
                    'position' => $category->position,
                    'image_url' => $category->image_path
                        ? Storage::disk('public')->url($category->image_path)
                        : null,
                    'translations' => $category->translations->keyBy('locale'),
                    'parent' => $category->parent?->translations->keyBy('locale'),
                    'children_count' => (int) $category->children_count,
                ]),
        ]);
    }

    /**
     * Categories whose name in any language, link, or main section's name has
     * every word of the search, spelled as the store search spells them
     * (ة/ه, أ/ا, ى/ي are one letter). Names are stored as typed, so they are
     * compared here rather than in SQL; a store has tens of categories, not
     * thousands.
     *
     * @return list<int>
     */
    private function matchingCategoryIds(string $search): array
    {
        $terms = SearchText::terms($search);

        if ($terms === []) {
            return [];
        }

        return array_values(Category::query()
            ->with(['translations', 'parent.translations'])
            ->get()
            ->filter(function (Category $category) use ($terms): bool {
                $text = SearchText::normalize(implode(' ', [
                    ...$category->translations->pluck('name')->all(),
                    ...$category->translations->pluck('slug')->map(fn (?string $slug): string => str_replace('-', ' ', (string) $slug))->all(),
                    ...($category->parent?->translations->pluck('name')->all() ?? []),
                ]));

                return collect($terms)->every(fn (array $spellings): bool => collect($spellings)
                    ->contains(fn (string $spelling): bool => str_contains($text, $spelling)));
            })
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }

    /**
     * Main sections a category can be placed under.
     *
     * @return list<array{value: string, label: string}>
     */
    private function parentOptions(?Category $category): array
    {
        $locale = app()->getLocale();

        return array_values(Category::query()
            ->whereNull('parent_id')
            ->when($category, fn (Builder $query, Category $self) => $query->whereKeyNot($self->id))
            ->with('translations')
            ->orderBy('position')
            ->get()
            ->map(fn (Category $parent): array => [
                'value' => (string) $parent->id,
                'label' => (string) ($parent->translations->firstWhere('locale', $locale)->name ?? $parent->translations->first()->name ?? "#{$parent->id}"),
            ])
            ->all());
    }

    public function createCategory(): Response
    {
        $this->authorize('create', Category::class);

        return Inertia::render('admin/categories/form', [
            'category' => null,
            'parents' => $this->parentOptions(null),
            // "Save and add another" keeps the main section of the last one.
            'defaults' => ['parent_id' => request()->integer('parent_id') ?: null],
            'back' => AdminReturnUrl::for('/admin/categories', request()->input('back')),
        ]);
    }

    public function storeCategory(StoreCategoryRequest $request, ImageUploads $uploads): RedirectResponse
    {
        $this->authorize('create', Category::class);

        $data = $request->validated();
        $data['translations'] = Slugs::fill($data['translations'], 'category_translations', 'category_id', null, 'category');
        $category = DB::transaction(function () use ($data, $request, $uploads): Category {
            $category = Category::create([
                'is_active' => $data['is_active'],
                'show_on_home' => $data['show_on_home'] ?? false,
                'position' => $data['position'],
                'parent_id' => $data['parent_id'] ?? null,
            ]);
            $this->applyCategoryImage($category, $request, $uploads);

            foreach ($data['translations'] as $locale => $translation) {
                $category->translations()->create([
                    'locale' => $locale,
                    ...$translation,
                ]);
            }

            $category->load('translations');
            $this->recordCatalogActivity('category.created', $category, null, $this->categorySnapshot($category));

            return $category;
        });

        return $this->afterSave($request, '/admin/categories', $category, __('Category ":name" added.', ['name' => $this->categoryName($category)]), [
            'parent_id' => $category->parent_id,
        ]);
    }

    public function editCategory(Category $category): Response
    {
        $this->authorize('update', $category);

        return Inertia::render('admin/categories/form', [
            'back' => AdminReturnUrl::for('/admin/categories', request()->input('back')),
            'parents' => $this->parentOptions($category),
            'category' => [
                ...$category->only(['id', 'is_active', 'show_on_home', 'position', 'parent_id']),
                'has_children' => $category->children()->exists(),
                'image_url' => $category->image_path ? Storage::disk('public')->url($category->image_path) : null,
                'translations' => $category->translations()->get()->keyBy('locale'),
            ],
        ]);
    }

    public function updateCategory(StoreCategoryRequest $request, Category $category, ImageUploads $uploads): RedirectResponse
    {
        $this->authorize('update', $category);

        $data = $request->validated();
        $data['translations'] = Slugs::fill($data['translations'], 'category_translations', 'category_id', $category->id, 'category');
        DB::transaction(function () use ($data, $category, $request, $uploads): void {
            $before = $this->categorySnapshot($category);
            $category->update([
                'is_active' => $data['is_active'],
                'show_on_home' => $data['show_on_home'] ?? false,
                'position' => $data['position'],
                'parent_id' => $data['parent_id'] ?? null,
            ]);
            $this->applyCategoryImage($category, $request, $uploads);

            foreach ($data['translations'] as $locale => $translation) {
                $category->translations()->updateOrCreate(
                    ['locale' => $locale],
                    $translation,
                );
            }

            $category->load('translations');
            $this->recordCatalogActivity('category.updated', $category, $before, $this->categorySnapshot($category));
        });

        // An offer on a main section covers the categories under it.
        if ($category->wasChanged('parent_id')) {
            ProductPricing::refreshAll();
        }

        return $this->afterSave($request, '/admin/categories', $category, __('Category ":name" saved.', ['name' => $this->categoryName($category)]));
    }

    public function toggleCategoryVisibility(Category $category): RedirectResponse
    {
        $this->authorize('update', $category);

        DB::transaction(function () use ($category): void {
            $before = $this->categorySnapshot($category);
            $category->update(['is_active' => ! $category->is_active]);
            $this->recordCatalogActivity($category->is_active ? 'category.shown' : 'category.hidden', $category, $before, $this->categorySnapshot($category));
        });

        return back()->with('success', __($category->is_active ? 'The category is shown in the store.' : 'The category is hidden from the store.'));
    }

    /**
     * Deleting a category would silently leave its products without one, so it
     * must be empty first.
     */
    public function deleteCategory(Category $category, ImageUploads $uploads): RedirectResponse
    {
        $this->authorize('delete', $category);

        $count = Product::withTrashed()->where('category_id', $category->id)->count();

        if ($count > 0) {
            return back()->withErrors([
                'category' => __('This category still has :count products. Move them to another category or delete them first.', ['count' => $count]),
            ]);
        }

        DB::transaction(function () use ($category, $uploads): void {
            $category->load('translations');
            $before = $this->categorySnapshot($category);
            $image = $category->image_path;
            $thumb = $category->thumb_path;

            $category->forceDelete();
            $this->recordCatalogActivity('category.deleted', $category, $before, ['deleted' => true]);

            // Imported catalog images may be shared by a re-import; only uploads go.
            if ($image !== null && str_starts_with($image, 'categories/')) {
                $uploads->deleteAfterCommit($image);
                $uploads->deleteAfterCommit($thumb);
            }
        });

        return to_route('admin.categories.index')->with('success', __('Category deleted permanently.'));
    }

    /**
     * @return array<int, array{id: int, names: array<string, string>}>
     */
    private function categoryOptions(): array
    {
        return Category::query()
            ->where('is_active', true)
            ->with('translations')
            ->orderBy('position')
            ->get()
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'names' => $category->translations->pluck('name', 'locale')->all(),
            ])
            ->all();
    }

    /**
     * @return array<int, array{id: int, names: array<string, string>}>
     */
    private function brandOptions(): array
    {
        return Brand::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name'])
            ->map(function (Brand $brand): array {
                $names = $brand->getAttribute('name');

                return [
                    'id' => $brand->id,
                    'names' => is_array($names) ? $names : [],
                ];
            })
            ->all();
    }

    /**
     * Where the form goes after a save, chosen by its button: back to the list
     * with the saved row highlighted (the default), an empty form for the next
     * one, or the same form.
     *
     * @param  array<string, int|null>  $prefill  Kept on the next empty form.
     */
    private function afterSave(Request $request, string $list, Model $saved, string $message, array $prefill = []): RedirectResponse
    {
        $back = AdminReturnUrl::for($list, $request->input('back'));

        $redirect = match ($request->input('after')) {
            'new' => redirect($list.'/create?'.http_build_query(array_filter([...$prefill, 'back' => $back]))),
            'stay' => redirect("{$list}/{$saved->getKey()}/edit?".http_build_query(['back' => $back])),
            default => redirect($back)->with('highlight', $saved->getKey()),
        };

        return $redirect->with('success', $message);
    }

    private function productName(Product $product): string
    {
        $names = $product->translations->pluck('name', 'locale');

        return (string) ($names[app()->getLocale()] ?? $names['ar'] ?? $names->first() ?? "#{$product->id}");
    }

    private function categoryName(Category $category): string
    {
        $names = $category->translations->pluck('name', 'locale');

        return (string) ($names[app()->getLocale()] ?? $names['ar'] ?? $names->first() ?? "#{$category->id}");
    }

    /**
     * @return array<string, mixed>
     */
    private function productSnapshot(Product $product): array
    {
        return [
            ...$product->only(['category_id', 'brand_id', 'sku', 'price', 'discount_percent', 'currency', 'stock_quantity', 'is_active', 'is_featured', 'price_is_estimate']),
            'translations' => $product->translations
                ->map(fn ($translation) => $translation->only(['locale', 'name', 'slug']))
                ->values()
                ->all(),
            'images' => $product->images()->pluck('path')->all(),
            'colors' => $product->colors()->pluck('hex')->all(),
        ];
    }

    /**
     * The form sends sync_images because an empty list, meaning "remove them all",
     * cannot travel in multipart data.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncImages(Product $product, array $data, ProductImageStore $images): void
    {
        if ($data['sync_images'] ?? false) {
            $images->sync($product, $data['image_order'] ?? [], $data['new_images'] ?? []);
        }
    }

    /**
     * Applies the color list from the form in its order. Colors keep their ids
     * when edited, so carts holding them stay valid; a removed color leaves the
     * carts that held it. A name typed only in Arabic is used for every language.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncColors(Product $product, array $data): void
    {
        if (! ($data['sync_colors'] ?? false)) {
            return;
        }

        $existing = $product->colors()->get()->keyBy('id');
        $kept = [];

        foreach (array_values($data['colors'] ?? []) as $position => $color) {
            $arabic = (string) $color['name']['ar'];
            $attributes = [
                'hex' => strtoupper((string) $color['hex']),
                'name' => [
                    'ar' => $arabic,
                    'en' => filled($color['name']['en'] ?? null) ? $color['name']['en'] : $arabic,
                    'he' => filled($color['name']['he'] ?? null) ? $color['name']['he'] : $arabic,
                ],
                'position' => $position,
            ];
            $current = isset($color['id']) ? $existing->get((int) $color['id']) : null;

            if ($current !== null) {
                $current->update($attributes);
                $kept[] = $current->id;
            } else {
                $kept[] = $product->colors()->create($attributes)->id;
            }
        }

        $product->colors()->whereKeyNot($kept)->delete();
    }

    /**
     * Sets the stock to the quantity typed in the form through the same action as
     * the inventory page, so the change is recorded with who made it and why.
     * The form also sends the quantity it was opened with; if an order or another
     * person changed the stock since, nothing is overwritten.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyStock(Product $product, array $data, StoreProductRequest $request, AdjustProductStock $stock, string $reason): void
    {
        if (! isset($data['stock_quantity']) || ! ($request->user()?->role->canManageInventory() ?? false)) {
            return;
        }

        $current = (int) Product::query()->lockForUpdate()->whereKey($product->id)->value('stock_quantity');

        if ((int) ($data['stock_quantity_was'] ?? 0) !== $current) {
            throw ValidationException::withMessages([
                'stock_quantity' => __('The stock changed to :count while you were editing. Check the new quantity and save again.', ['count' => $current]),
            ]);
        }

        $target = (int) $data['stock_quantity'];

        if ($target === $current) {
            return;
        }

        /** @var User $actor */
        $actor = $request->user();
        $stock->execute($product, $actor, $target > $current ? 'increase' : 'decrease', abs($target - $current), $reason);
    }

    /**
     * A new file replaces the old one; remove_image clears it. The old file is
     * deleted after commit, and only if this form uploaded it: imported catalog
     * images under catalog/ are left alone, since a re-import may reuse them.
     */
    private function applyCategoryImage(Category $category, StoreCategoryRequest $request, ImageUploads $uploads): void
    {
        $old = $category->image_path;
        $file = $request->file('image');

        $oldThumb = $category->thumb_path;

        if ($file instanceof UploadedFile) {
            $path = $uploads->store($file, "categories/{$category->id}", self::CATEGORY_IMAGE_SIDE, 'image');
            $category->update(['image_path' => $path, 'thumb_path' => $uploads->thumbnail($path, ImageUploads::CATEGORY_THUMBNAIL_SIDE)]);
        } elseif ($request->boolean('remove_image')) {
            $category->update(['image_path' => null, 'thumb_path' => null]);
        }

        if ($old !== null && $old !== $category->image_path && str_starts_with($old, 'categories/')) {
            $uploads->deleteAfterCommit($old);
            $uploads->deleteAfterCommit($oldThumb);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function categorySnapshot(Category $category): array
    {
        return [
            ...$category->only(['is_active', 'show_on_home', 'position', 'image_path', 'parent_id']),
            'translations' => $category->translations
                ->map(fn ($translation) => $translation->only(['locale', 'name', 'slug']))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    private function recordCatalogActivity(string $action, Model $subject, ?array $before, array $after): void
    {
        CatalogActivity::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'changes' => ['before' => $before, 'after' => $after],
            'created_at' => now(),
        ]);
    }
}
