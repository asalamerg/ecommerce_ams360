import { Head, Link, usePage } from '@inertiajs/react';
import { Heart } from 'lucide-react';
import { useEffect, useState } from 'react';
import ProductCard from '@/components/store/product-card';
import { keepFavorites, useFavorites } from '@/hooks/use-favorites';
import { useTranslations } from '@/hooks/use-translations';
import StorefrontLayout from '@/layouts/storefront-layout';
import type { ProductCard as Card } from '@/types';

/**
 * Favorites are kept in this browser, so the page reads them there and asks the
 * server for the cards of products still for sale.
 */
export default function Favorites() {
    const { locale } = usePage().props;
    const t = useTranslations();
    const favorites = useFavorites();
    const [products, setProducts] = useState<Card[] | null>(null);
    const ids = favorites.join(',');

    useEffect(() => {
        if (ids === '') {
            setProducts([]);

            return;
        }

        const controller = new AbortController();

        fetch(`/${locale}/favorites/products?ids=${ids}`, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then((response) => response.json())
            .then((data: { products: Card[] }) => {
                setProducts(data.products);
                keepFavorites(data.products.map((product) => product.id));
            })
            .catch(() => {
                if (!controller.signal.aborted) {
                    setProducts([]);
                }
            });

        return () => controller.abort();
    }, [ids, locale]);

    // Removing a heart on this page takes the card away at once.
    const shown = (products ?? []).filter((product) =>
        favorites.includes(product.id),
    );

    return (
        <StorefrontLayout>
            <Head title={t('store.favorites')} />
            <main className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <h1 className="flex items-center gap-2 text-3xl font-black">
                    <Heart
                        size={28}
                        aria-hidden="true"
                        className="fill-rose-500 text-rose-500"
                    />
                    {t('store.favorites')}
                </h1>
                <p className="mt-1 text-sm text-ink-soft">
                    {t('store.favorites_note')}
                </p>

                {products === null ? (
                    <div className="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4">
                        {favorites.slice(0, 8).map((id) => (
                            <div
                                key={id}
                                className="image-loading aspect-[3/4] rounded-panel"
                            />
                        ))}
                    </div>
                ) : shown.length === 0 ? (
                    <div className="mt-10 rounded-panel border border-dashed border-line p-10 text-center">
                        <Heart
                            size={40}
                            aria-hidden="true"
                            className="mx-auto text-ink-soft"
                        />
                        <p className="mt-3 font-bold">
                            {t('store.no_favorites')}
                        </p>
                        <p className="mt-1 text-sm text-ink-soft">
                            {t('store.no_favorites_hint')}
                        </p>
                        <Link
                            href={`/${locale}/products`}
                            className="mt-5 inline-flex rounded-btn bg-brand px-5 py-2.5 text-sm font-bold text-on-brand transition hover:bg-brand-hover"
                        >
                            {t('store.keep_shopping')}
                        </Link>
                    </div>
                ) : (
                    <div className="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4">
                        {shown.map((product) => (
                            <ProductCard key={product.id} product={product} />
                        ))}
                    </div>
                )}
            </main>
        </StorefrontLayout>
    );
}
