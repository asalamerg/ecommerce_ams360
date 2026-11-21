import { Link, usePage } from '@inertiajs/react';
import { Heart } from 'lucide-react';
import { useFavorites } from '@/hooks/use-favorites';
import { useTranslations } from '@/hooks/use-translations';

/** The heart in the header, with how many products are saved. */
export default function FavoritesLink() {
    const { locale } = usePage().props;
    const t = useTranslations();
    const count = useFavorites().length;

    return (
        <Link
            href={`/${locale}/favorites`}
            aria-label={`${t('store.favorites')} (${count})`}
            title={t('store.favorites')}
            className="relative grid h-10 w-10 place-items-center rounded-full text-ink transition hover:bg-surface hover:text-rose-600"
        >
            <Heart
                size={20}
                aria-hidden="true"
                className={count > 0 ? 'fill-rose-500 text-rose-500' : ''}
            />
            {count > 0 && (
                <span
                    key={count}
                    className="badge-pop absolute -end-0.5 -top-0.5 grid h-5 min-w-5 place-items-center rounded-full bg-rose-500 px-1 text-[11px] font-bold text-white"
                >
                    {count > 9 ? '9+' : count}
                </span>
            )}
        </Link>
    );
}
