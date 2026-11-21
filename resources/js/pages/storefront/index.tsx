import { Link, router, usePage } from '@inertiajs/react';
import { PackageSearch, SlidersHorizontal } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import OfferBanner from '@/components/store/offer-banner';
import ProductCard from '@/components/store/product-card';
import Rail from '@/components/store/rail';
import SeoTitle from '@/components/store/seo-title';
import { useTranslations } from '@/hooks/use-translations';
import StorefrontLayout from '@/layouts/storefront-layout';
import { cn } from '@/lib/utils';
import type {
    CategoryCard,
    OfferCard,
    PageLink,
    ProductCard as Product,
} from '@/types';

type Props = {
    products: { data: Product[]; links: PageLink[]; total: number };
    categories: CategoryCard[];
    search: string;
    sort: string;
    selectedCategory: string | null;
    filters: {
        price_min: string | null;
        price_max: string | null;
        brand: string | null;
        available: boolean;
        sale: boolean;
        featured: boolean;
    };
    brands: { slug: string; name: string }[];
    subcategories: CategoryCard[];
    parentCategory: { name: string; slug: string } | null;
    offerPage: OfferCard | null;
    brandPage: {
        slug: string;
        name: string;
        description: string | null;
        logo_url: string | null;
    } | null;
};

export default function StorefrontIndex({
    products,
    categories,
    search,
    sort,
    selectedCategory,
    filters,
    brands,
    subcategories,
    parentCategory,
    brandPage,
    offerPage,
}: Props) {
    const { url, props } = usePage();
    const { locale } = props;
    const t = useTranslations();
    const current = categories.find(
        (category) => category.slug === selectedCategory,
    );
    const title = search
        ? `"${search}"`
        : (offerPage?.title ??
          brandPage?.name ??
          current?.name ??
          (filters.featured ? t('store.featured') : null) ??
          (filters.sale ? t('store.deals') : null) ??
          t('store.products'));

    const [logoFailed, setLogoFailed] = useState(false);
    const [priceMin, setPriceMin] = useState(filters.price_min ?? '');
    const [priceMax, setPriceMax] = useState(filters.price_max ?? '');
    const activeFilters =
        !!filters.price_min ||
        !!filters.price_max ||
        !!filters.brand ||
        filters.available ||
        filters.sale;
    const [showFilters, setShowFilters] = useState(activeFilters);

    function visit(next: Record<string, string | boolean | null>) {
        const query: Record<string, string> = {};
        const all: Record<string, string | boolean | null> = {
            search,
            sort: sort === 'newest' ? null : sort,
            price_min: filters.price_min,
            price_max: filters.price_max,
            brand: filters.brand,
            available: filters.available ? '1' : null,
            sale: filters.sale ? '1' : null,
            featured: filters.featured ? '1' : null,
            ...next,
        };

        Object.entries(all).forEach(([key, value]) => {
            if (value !== null && value !== '' && value !== false) {
                query[key] = value === true ? '1' : String(value);
            }
        });

        router.get(url.split('?')[0], query, { preserveScroll: true });
    }

    function changeSort(value: string) {
        visit({ sort: value === 'newest' ? null : value });
    }

    function applyPrice(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        visit({ price_min: priceMin || null, price_max: priceMax || null });
    }

    return (
        <StorefrontLayout>
            <SeoTitle />
            <main className="mx-auto max-w-7xl px-4 py-6 sm:px-6 sm:py-8 lg:px-8">
                <nav aria-label={t('store.home')} className="mb-2 text-sm">
                    <Link
                        href={`/${locale}`}
                        className="text-ink-soft hover:text-brand-text"
                    >
                        {t('store.home')}
                    </Link>
                    <span className="mx-2 text-ink-soft">/</span>
                    {parentCategory && (
                        <>
                            <Link
                                href={`/${locale}/categories/${parentCategory.slug}`}
                                className="text-ink-soft hover:text-brand-text"
                            >
                                {parentCategory.name}
                            </Link>
                            <span className="mx-2 text-ink-soft">/</span>
                        </>
                    )}
                    {brandPage && (
                        <>
                            <Link
                                href={`/${locale}/brands`}
                                className="text-ink-soft hover:text-brand-text"
                            >
                                {t('brands.title')}
                            </Link>
                            <span className="mx-2 text-ink-soft">/</span>
                        </>
                    )}
                    <span className="font-semibold text-ink">{title}</span>
                </nav>

                {offerPage && (
                    <OfferBanner
                        offer={offerPage}
                        heading="h1"
                        asLink={false}
                        className="mb-6"
                    />
                )}

                {brandPage && (
                    <div className="mb-6 flex items-center gap-4 rounded-panel border border-line bg-panel p-4 sm:gap-6 sm:p-5">
                        <span className="grid h-20 w-28 shrink-0 place-items-center overflow-hidden rounded-xl border border-line bg-white sm:h-24 sm:w-36">
                            {brandPage.logo_url && !logoFailed ? (
                                <img
                                    src={brandPage.logo_url}
                                    alt={brandPage.name}
                                    onError={() => setLogoFailed(true)}
                                    className="max-h-[70%] max-w-[80%] object-contain"
                                />
                            ) : (
                                <span
                                    aria-hidden="true"
                                    className="text-4xl font-black text-brand-text"
                                >
                                    {brandPage.name.charAt(0)}
                                </span>
                            )}
                        </span>
                        <div className="min-w-0">
                            <h1 className="text-2xl font-black sm:text-3xl">
                                {search ? title : brandPage.name}
                            </h1>
                            <p className="mt-0.5 text-sm font-semibold text-brand-text">
                                {t('store.products_count', {
                                    count: products.total,
                                })}
                            </p>
                            <p className="mt-2 line-clamp-3 text-sm leading-6 whitespace-pre-line text-ink-soft">
                                {brandPage.description ??
                                    t('brands.page_line', {
                                        name: brandPage.name,
                                    })}
                            </p>
                        </div>
                    </div>
                )}

                <div className="mb-5 flex flex-wrap items-end justify-between gap-3">
                    {/* A brand or offer page has its name in the card above. */}
                    {brandPage || offerPage ? (
                        <span />
                    ) : (
                        <div>
                            <h1 className="text-2xl font-black sm:text-3xl">
                                {title}
                            </h1>
                            <p className="mt-1 text-sm text-ink-soft">
                                {t('store.products_count', {
                                    count: products.total,
                                })}
                            </p>
                        </div>
                    )}
                    <label className="flex items-center gap-2 text-sm font-semibold">
                        {t('store.sort')}
                        <select
                            value={sort}
                            onChange={(event) => changeSort(event.target.value)}
                            className="admin-input w-auto"
                        >
                            <option value="newest">
                                {t('store.sort_newest')}
                            </option>
                            <option value="price_asc">
                                {t('store.sort_price_asc')}
                            </option>
                            <option value="price_desc">
                                {t('store.sort_price_desc')}
                            </option>
                        </select>
                    </label>
                </div>

                <div className="mb-4">
                    <button
                        type="button"
                        aria-expanded={showFilters}
                        onClick={() => setShowFilters((open) => !open)}
                        className="inline-flex items-center gap-2 rounded-full border border-line bg-panel px-4 py-2 text-sm font-bold transition hover:border-brand"
                    >
                        <SlidersHorizontal size={16} aria-hidden="true" />
                        {t('filters.title')}
                        {activeFilters && (
                            <span className="h-2 w-2 rounded-full bg-brand" />
                        )}
                    </button>
                    {showFilters && (
                        <div className="mt-3 grid gap-4 rounded-panel border border-line bg-panel p-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                            <form
                                onSubmit={applyPrice}
                                className="grid gap-1.5 text-sm font-semibold"
                            >
                                {t('filters.price')}
                                <div
                                    className="flex items-center gap-2"
                                    dir="ltr"
                                >
                                    <input
                                        type="number"
                                        min={0}
                                        inputMode="numeric"
                                        value={priceMin}
                                        onChange={(event) =>
                                            setPriceMin(event.target.value)
                                        }
                                        placeholder={t('filters.min')}
                                        aria-label={t('filters.min')}
                                        className="admin-input min-w-0"
                                    />
                                    <span aria-hidden="true">–</span>
                                    <input
                                        type="number"
                                        min={0}
                                        inputMode="numeric"
                                        value={priceMax}
                                        onChange={(event) =>
                                            setPriceMax(event.target.value)
                                        }
                                        placeholder={t('filters.max')}
                                        aria-label={t('filters.max')}
                                        className="admin-input min-w-0"
                                    />
                                    <button
                                        type="submit"
                                        className="shrink-0 rounded-btn bg-brand px-3 py-2 text-sm font-bold text-on-brand"
                                    >
                                        {t('filters.apply')}
                                    </button>
                                </div>
                            </form>
                            {brands.length > 0 && !brandPage && (
                                <label className="grid gap-1.5 text-sm font-semibold">
                                    {t('filters.brand')}
                                    <select
                                        value={filters.brand ?? ''}
                                        onChange={(event) =>
                                            visit({
                                                brand:
                                                    event.target.value || null,
                                            })
                                        }
                                        className="admin-input"
                                    >
                                        <option value="">
                                            {t('filters.any_brand')}
                                        </option>
                                        {brands.map((brand) => (
                                            <option
                                                key={brand.slug}
                                                value={brand.slug}
                                            >
                                                {brand.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            )}
                            <div className="flex flex-wrap items-center gap-4">
                                <label className="flex items-center gap-2 text-sm font-semibold">
                                    <input
                                        type="checkbox"
                                        checked={filters.available}
                                        onChange={(event) =>
                                            visit({
                                                available: event.target.checked,
                                            })
                                        }
                                        className="admin-checkbox"
                                    />
                                    {t('filters.available')}
                                </label>
                                <label className="flex items-center gap-2 text-sm font-semibold">
                                    <input
                                        type="checkbox"
                                        checked={filters.sale}
                                        onChange={(event) =>
                                            visit({
                                                sale: event.target.checked,
                                            })
                                        }
                                        className="admin-checkbox"
                                    />
                                    {t('filters.on_sale')}
                                </label>
                                {activeFilters && (
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setPriceMin('');
                                            setPriceMax('');
                                            visit({
                                                price_min: null,
                                                price_max: null,
                                                brand: null,
                                                available: null,
                                                sale: null,
                                            });
                                        }}
                                        className="text-sm font-bold text-brand-text hover:underline"
                                    >
                                        {t('filters.clear')}
                                    </button>
                                )}
                            </div>
                        </div>
                    )}
                </div>

                {(subcategories.length > 0 ? subcategories : categories)
                    .length > 0 && (
                    <Rail label={t('store.categories')} className="mb-6">
                        {(subcategories.length > 0
                            ? subcategories
                            : categories
                        ).map((category) => (
                            <Link
                                key={category.slug}
                                href={`/${locale}/categories/${category.slug}`}
                                className={cn(
                                    'shrink-0 snap-start rounded-full border px-4 py-2 text-sm font-semibold whitespace-nowrap transition hover:border-brand hover:text-brand-text',
                                    selectedCategory === category.slug
                                        ? 'border-brand bg-brand/10 text-brand-text'
                                        : 'border-line bg-panel text-ink/80',
                                )}
                            >
                                {category.name}
                            </Link>
                        ))}
                    </Rail>
                )}

                {products.data.length > 0 ? (
                    <div className="grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                        {products.data.map((product) => (
                            <ProductCard key={product.id} product={product} />
                        ))}
                    </div>
                ) : (
                    <div className="rounded-panel border border-dashed border-line bg-panel px-6 py-16 text-center text-ink-soft">
                        <PackageSearch
                            size={36}
                            aria-hidden="true"
                            className="mx-auto mb-3"
                        />
                        {search ? (
                            <>
                                <p>{t('store.no_results')}</p>
                                <Link
                                    href={`/${locale}/products`}
                                    className="mt-3 inline-block font-bold text-brand-text hover:underline"
                                >
                                    {t('nav.all_products')}
                                </Link>
                            </>
                        ) : (
                            t('store.empty')
                        )}
                    </div>
                )}

                {products.links.length > 3 && (
                    <nav
                        aria-label="Pagination"
                        className="mt-8 flex flex-wrap justify-center gap-1.5"
                    >
                        {products.links.map((link, index) =>
                            link.url ? (
                                <Link
                                    key={`${link.label}-${index}`}
                                    href={link.url}
                                    className={cn(
                                        'grid h-10 min-w-10 place-items-center rounded-lg border px-3 text-sm font-semibold transition',
                                        link.active
                                            ? 'border-brand bg-brand text-on-brand'
                                            : 'border-line bg-panel text-ink hover:border-brand',
                                    )}
                                >
                                    {link.label
                                        .replace('&laquo;', '‹')
                                        .replace('&raquo;', '›')
                                        .replace('Previous', '')
                                        .replace('Next', '')
                                        .trim()}
                                </Link>
                            ) : null,
                        )}
                    </nav>
                )}
            </main>
        </StorefrontLayout>
    );
}
