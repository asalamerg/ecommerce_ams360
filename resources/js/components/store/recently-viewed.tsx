import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ProductCard from '@/components/store/product-card';
import Rail from '@/components/store/rail';
import { useTranslations } from '@/hooks/use-translations';
import type { ProductCard as Card } from '@/types';

const KEY = 'store.recent';
const MAX = 12;

function read(): number[] {
    try {
        const parsed: unknown = JSON.parse(localStorage.getItem(KEY) ?? '[]');

        return Array.isArray(parsed)
            ? parsed.filter(
                  (id): id is number => Number.isInteger(id) && id > 0,
              )
            : [];
    } catch {
        return [];
    }
}

/** Remembers a product as just seen, in this browser only. */
export function rememberViewed(id: number): void {
    try {
        localStorage.setItem(
            KEY,
            JSON.stringify(
                [id, ...read().filter((seen) => seen !== id)].slice(0, MAX),
            ),
        );
    } catch {
        // Private mode: nothing is remembered.
    }
}

/**
 * Products this browser looked at recently. Rendered after load only, so
 * server-rendered pages and their cache stay the same for everyone.
 */
export default function RecentlyViewed({ exclude }: { exclude?: number }) {
    const { locale } = usePage().props;
    const t = useTranslations();
    const [cards, setCards] = useState<Card[]>([]);

    useEffect(() => {
        const ids = read().filter((id) => id !== exclude);

        if (!ids.length) {
            return;
        }

        const controller = new AbortController();
        fetch(`/${locale}/favorites/products?ids=${ids.join(',')}`, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then((response) =>
                response.ok ? response.json() : { products: [] },
            )
            .then((data: { products: Card[] }) => setCards(data.products))
            .catch(() => undefined);

        return () => controller.abort();
    }, [locale, exclude]);

    if (!cards.length) {
        return null;
    }

    return (
        <section aria-labelledby="recently-viewed" className="mt-10">
            <h2 id="recently-viewed" className="mb-4 text-xl font-black">
                {t('store.recently_viewed')}
            </h2>
            <Rail label={t('store.recently_viewed')}>
                {cards.map((card) => (
                    <ProductCard
                        key={card.id}
                        product={card}
                        className="w-44 shrink-0 snap-start sm:w-52 lg:w-56"
                    />
                ))}
            </Rail>
        </section>
    );
}
