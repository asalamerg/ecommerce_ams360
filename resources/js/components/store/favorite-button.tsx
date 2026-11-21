import { Heart } from 'lucide-react';
import { useState } from 'react';
import { toggleFavorite, useFavorites } from '@/hooks/use-favorites';
import { useTranslations } from '@/hooks/use-translations';
import { cn } from '@/lib/utils';

/**
 * The heart on cards and product pages. Fills red and pops when saved; the
 * favorites page lists everything saved in this browser.
 */
export default function FavoriteButton({
    productId,
    className,
    size = 18,
}: {
    productId: number;
    className?: string;
    size?: number;
}) {
    const t = useTranslations();
    const favorites = useFavorites();
    const saved = favorites.includes(productId);
    const [pop, setPop] = useState(0);

    return (
        <button
            type="button"
            onClick={(event) => {
                // The heart sits on the product link; it must not open the page.
                event.preventDefault();
                event.stopPropagation();

                if (toggleFavorite(productId)) {
                    setPop((count) => count + 1);
                }
            }}
            aria-pressed={saved}
            aria-label={t(
                saved ? 'store.remove_favorite' : 'store.add_favorite',
            )}
            title={t(saved ? 'store.remove_favorite' : 'store.add_favorite')}
            className={cn(
                'grid place-items-center rounded-full bg-panel/90 text-ink-soft shadow-sm ring-1 ring-line backdrop-blur transition hover:text-rose-600 active:scale-90 motion-reduce:transform-none',
                saved && 'text-rose-600',
                className,
            )}
        >
            <Heart
                key={pop}
                size={size}
                aria-hidden="true"
                className={cn(saved && 'fill-current', pop > 0 && 'heart-pop')}
            />
        </button>
    );
}
