import { Head, Link, usePage } from '@inertiajs/react';
import { GitCompareArrows, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useState } from 'react';
import { ColorDots } from '@/components/store/color-dots';
import { Stars } from '@/components/store/product-reviews';
import { toggleCompare, useCompare } from '@/hooks/use-compare';
import { useTranslations } from '@/hooks/use-translations';
import StorefrontLayout from '@/layouts/storefront-layout';
import { formatMoney } from '@/lib/store';
import type { ProductCard } from '@/types';

type Compared = ProductCard & {
    sku: string | null;
    summary: string | null;
    rating: { average: number; count: number };
};

/**
 * Up to four products side by side. The list lives in this browser, so the
 * page asks for the cards after load; it is kept out of search engines.
 */
export default function ComparePage() {
    const { locale } = usePage().props;
    const t = useTranslations();
    const ids = useCompare();
    const [products, setProducts] = useState<Compared[] | null>(null);
    const key = ids.join(',');

    useEffect(() => {
        if (!key) {
            setProducts([]);

            return;
        }

        const controller = new AbortController();
        fetch(`/${locale}/compare/products?ids=${key}`, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then((response) =>
                response.ok ? response.json() : { products: [] },
            )
            .then((data: { products: Compared[] }) =>
                setProducts(data.products),
            )
            .catch(() => undefined);

        return () => controller.abort();
    }, [key, locale]);

    const shown = (products ?? []).filter((product) =>
        ids.includes(product.id),
    );
    const rows: [string, (product: Compared) => ReactNode][] = [
        [
            t('compare.price'),
            (product) => (
                <span className="font-black" dir="ltr">
                    {product.price_is_estimate ? '≈ ' : ''}
                    {formatMoney(product.price, product.currency, 'en')}
                </span>
            ),
        ],
        [t('filters.brand'), (product) => product.brand ?? '—'],
        [t('compare.category'), (product) => product.category ?? '—'],
        [
            t('compare.availability'),
            (product) =>
                product.purchasable ? (
                    <span className="font-semibold text-emerald-700">
                        {t('compare.available')}
                    </span>
                ) : (
                    <span className="text-rose-700">
                        {t('store.out_of_stock')}
                    </span>
                ),
        ],
        [
            t('compare.colors'),
            (product) =>
                product.colors.length ? (
                    <ColorDots colors={product.colors} />
                ) : (
                    '—'
                ),
        ],
        [
            t('reviews.title'),
            (product) =>
                product.rating.count ? (
                    <span className="inline-flex items-center gap-1.5">
                        <Stars value={product.rating.average} size={14} />
                        <span className="text-xs text-ink-soft">
                            ({product.rating.count})
                        </span>
                    </span>
                ) : (
                    '—'
                ),
        ],
        [
            'SKU',
            (product) => (
                <span dir="ltr" className="text-xs">
                    {product.sku ?? '—'}
                </span>
            ),
        ],
        [
            t('store.description'),
            (product) => (
                <span className="text-xs leading-5 text-ink-soft">
                    {product.summary ?? '—'}
                </span>
            ),
        ],
    ];

    return (
        <StorefrontLayout>
            <Head title={t('compare.title')} />
            <main className="mx-auto max-w-7xl px-4 py-6 sm:px-6 sm:py-8 lg:px-8">
                <h1 className="mb-5 flex items-center gap-2 text-2xl font-black sm:text-3xl">
                    <GitCompareArrows size={26} aria-hidden="true" />
                    {t('compare.title')}
                </h1>

                {products === null ? (
                    <div className="h-64 animate-pulse rounded-panel bg-panel motion-reduce:animate-none" />
                ) : shown.length === 0 ? (
                    <div className="rounded-panel border border-dashed border-line bg-panel px-6 py-16 text-center text-ink-soft">
                        <p>{t('compare.empty')}</p>
                        <Link
                            href={`/${locale}/products`}
                            className="mt-3 inline-block font-bold text-brand-text hover:underline"
                        >
                            {t('nav.all_products')}
                        </Link>
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-panel border border-line bg-panel">
                        <table className="w-full min-w-[640px] table-fixed text-sm">
                            <thead>
                                <tr>
                                    <th className="w-32 p-3" />
                                    {shown.map((product) => (
                                        <th
                                            key={product.id}
                                            className="p-3 align-top font-normal"
                                        >
                                            <div className="relative">
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        toggleCompare(
                                                            product.id,
                                                        )
                                                    }
                                                    aria-label={t(
                                                        'compare.remove',
                                                    )}
                                                    className="absolute end-0 top-0 grid h-7 w-7 place-items-center rounded-full bg-panel/90 shadow hover:text-rose-700"
                                                >
                                                    <X
                                                        size={14}
                                                        aria-hidden="true"
                                                    />
                                                </button>
                                                <Link
                                                    href={`/${locale}/products/${product.slug}`}
                                                    className="block"
                                                >
                                                    <span className="block aspect-square overflow-hidden rounded-lg bg-surface">
                                                        {product.image_url && (
                                                            <img
                                                                src={
                                                                    product.image_url
                                                                }
                                                                alt=""
                                                                className="h-full w-full object-contain p-3"
                                                            />
                                                        )}
                                                    </span>
                                                    <span className="mt-2 line-clamp-2 block text-start font-bold text-ink hover:text-brand-text">
                                                        {product.name}
                                                    </span>
                                                </Link>
                                            </div>
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {rows.map(([label, value]) => (
                                    <tr key={label}>
                                        <th
                                            scope="row"
                                            className="bg-surface/60 p-3 text-start text-xs font-bold text-ink-soft"
                                        >
                                            {label}
                                        </th>
                                        {shown.map((product) => (
                                            <td
                                                key={product.id}
                                                className="p-3 align-top"
                                            >
                                                {value(product)}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </main>
        </StorefrontLayout>
    );
}
