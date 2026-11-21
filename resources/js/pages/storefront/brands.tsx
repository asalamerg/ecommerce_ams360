import { Link, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useState } from 'react';
import BrandTile from '@/components/store/brand-tile';
import type { BrandCard } from '@/components/store/brand-tile';
import SeoTitle from '@/components/store/seo-title';
import { useTranslations } from '@/hooks/use-translations';
import StorefrontLayout from '@/layouts/storefront-layout';
import { fold } from '@/lib/fold-text';

export default function StorefrontBrands({ brands }: { brands: BrandCard[] }) {
    const { locale } = usePage().props;
    const t = useTranslations();
    const [query, setQuery] = useState('');
    const term = fold(query.trim());
    const shown = brands.filter(
        (brand) =>
            !term || fold(`${brand.name} ${brand.keywords}`).includes(term),
    );

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
                    <span className="font-semibold text-ink">
                        {t('brands.title')}
                    </span>
                </nav>
                <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-black sm:text-3xl">
                            {t('brands.title')}
                        </h1>
                        <p className="mt-1 text-sm text-ink-soft">
                            {t('brands.page_intro', { count: brands.length })}
                        </p>
                    </div>
                    <label className="relative w-full sm:w-72">
                        <Search
                            size={16}
                            aria-hidden="true"
                            className="pointer-events-none absolute start-3 top-1/2 -translate-y-1/2 text-ink-soft"
                        />
                        <input
                            type="search"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder={t('brands.search')}
                            aria-label={t('brands.search')}
                            className="admin-input ps-9"
                        />
                    </label>
                </div>
                {shown.length ? (
                    <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 md:grid-cols-4 lg:grid-cols-6">
                        {shown.map((brand) => (
                            <li key={brand.slug}>
                                <BrandTile brand={brand} />
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="py-12 text-center text-ink-soft">
                        {t('brands.no_match')}
                    </p>
                )}
            </main>
        </StorefrontLayout>
    );
}
