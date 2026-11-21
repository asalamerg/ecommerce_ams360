import { Link, usePage } from '@inertiajs/react';
import { BadgePercent } from 'lucide-react';
import Countdown from '@/components/store/countdown';
import { useTranslations } from '@/hooks/use-translations';
import { formatMoney } from '@/lib/store';
import { cn } from '@/lib/utils';
import type { OfferCard } from '@/types';

export function useOfferLabel(): (offer: OfferCard) => string {
    const t = useTranslations();

    return (offer) =>
        offer.discount_type === 'percentage'
            ? t('store.sale_badge', {
                  percent: Number(offer.discount_value),
              })
            : t('store.offer_off_amount', {
                  amount: formatMoney(offer.discount_value, 'ILS', 'en'),
              });
}

/**
 * One running offer: its picture or a tinted panel, the saving, and the time
 * left. As a link on the home page; as the page header on the offer's page.
 */
export default function OfferBanner({
    offer,
    heading = 'h3',
    asLink = true,
    className,
}: {
    offer: OfferCard;
    heading?: 'h1' | 'h3';
    asLink?: boolean;
    className?: string;
}) {
    const { locale } = usePage().props;
    const t = useTranslations();
    const label = useOfferLabel()(offer);
    const Heading = heading;

    const body = (
        <>
            {offer.image_url && (
                <img
                    src={offer.image_url}
                    alt=""
                    loading="lazy"
                    decoding="async"
                    className="absolute inset-0 -z-10 h-full w-full object-cover transition duration-500 group-hover:scale-105 motion-reduce:transform-none"
                />
            )}
            <span
                className={cn(
                    'absolute inset-0 -z-10',
                    offer.image_url
                        ? 'bg-gradient-to-t from-black/80 via-black/40 to-black/10'
                        : 'bg-gradient-to-br from-sale to-sale/70',
                )}
            />
            <span className="inline-flex w-fit items-center gap-1.5 rounded-full bg-white px-3 py-1 text-sm font-black text-sale shadow-sm">
                <BadgePercent size={16} aria-hidden="true" />
                {label}
            </span>
            <Heading className="mt-3 text-xl leading-tight font-black sm:text-2xl">
                {offer.title}
            </Heading>
            {offer.description && (
                <p className="mt-1 line-clamp-2 max-w-xl text-sm leading-6 opacity-90">
                    {offer.description}
                </p>
            )}
            <span className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2">
                {offer.ends_at && <Countdown endsAt={offer.ends_at} />}
                {asLink && (
                    <span className="text-sm font-bold underline-offset-4 group-hover:underline">
                        {t('store.shop_offer')}
                    </span>
                )}
            </span>
        </>
    );

    const classes = cn(
        'group relative isolate flex min-h-44 flex-col justify-end overflow-hidden rounded-panel p-5 text-white shadow-sm sm:p-6',
        className,
    );

    return asLink ? (
        <Link href={`/${locale}/offers/${offer.id}`} className={classes}>
            {body}
        </Link>
    ) : (
        <div className={classes}>{body}</div>
    );
}
