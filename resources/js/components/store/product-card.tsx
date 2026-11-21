import { Link, usePage } from '@inertiajs/react';
import { Check, Palette, ShoppingCart } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { ColorDots } from '@/components/store/color-dots';
import FavoriteButton from '@/components/store/favorite-button';
import { Spinner } from '@/components/ui/spinner';
import { addToCart } from '@/hooks/use-cart';
import { useTranslations } from '@/hooks/use-translations';
import { formatMoney } from '@/lib/store';
import { cn } from '@/lib/utils';
import type { ProductCard as Product } from '@/types';

const LOW_STOCK = 3;

export function AvailabilityNote({ product }: { product: Product }) {
    const t = useTranslations();

    if (product.availability_unconfirmed) {
        return (
            <span className="inline-flex items-center gap-1.5 text-xs text-amber-800 dark:text-amber-300">
                <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500" />
                {t('store.availability_confirm')}
            </span>
        );
    }

    if (product.stock_quantity > 0 && product.stock_quantity <= LOW_STOCK) {
        return (
            <span className="inline-flex items-center gap-1.5 text-xs font-semibold text-urgent">
                <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-urgent motion-reduce:animate-none" />
                {t('store.low_stock', { count: product.stock_quantity })}
            </span>
        );
    }

    return (
        <span className="inline-flex items-center gap-1.5 text-xs text-ink-soft">
            <span
                className={cn(
                    'h-1.5 w-1.5 rounded-full',
                    product.stock_quantity > 0
                        ? 'bg-emerald-500'
                        : 'bg-ink-soft',
                )}
            />
            {t(
                product.stock_quantity > 0
                    ? 'store.in_stock'
                    : 'store.out_of_stock',
            )}
        </span>
    );
}

export default function ProductCard({
    product,
    className,
}: {
    product: Product;
    className?: string;
}) {
    const { locale } = usePage().props;
    const t = useTranslations();
    const image = useRef<HTMLImageElement>(null);
    const [adding, setAdding] = useState(false);
    const [added, setAdded] = useState(false);
    const [loaded, setLoaded] = useState(false);

    // A picture that arrived before the page became interactive fired its load
    // event before React listened, so the shimmer behind it would never stop.
    useEffect(() => {
        if (image.current?.complete) {
            setLoaded(true);
        }
    }, []);

    const href = `/${locale}/products/${product.slug}`;
    // A color has to be picked on the product page before it can go in the cart.
    const needsColor = product.colors.length > 0;

    function add() {
        setAdding(true);
        addToCart(product.id, 1, {
            image: image.current,
            onFinish: () => {
                setAdding(false);
                setAdded(true);
                window.setTimeout(() => setAdded(false), 1500);
            },
        });
    }

    return (
        <article
            className={cn(
                'card-surface group relative flex h-full flex-col overflow-hidden rounded-panel hover:-translate-y-1 motion-reduce:transform-none',
                className,
            )}
        >
            <Link
                href={href}
                prefetch
                aria-label={product.name}
                className={cn(
                    'relative block aspect-square overflow-hidden bg-surface',
                    product.image_url && !loaded && 'image-loading',
                )}
            >
                {product.image_url ? (
                    <>
                        <img
                            ref={image}
                            src={product.image_url}
                            alt={product.name}
                            loading="lazy"
                            decoding="async"
                            onLoad={() => setLoaded(true)}
                            onError={() => setLoaded(true)}
                            // Never hidden while loading: the picture shows as soon
                            // as it arrives, even before the page's JavaScript runs.
                            className={cn(
                                'absolute inset-0 h-full w-full object-contain p-4 transition duration-500 group-hover:scale-105 motion-reduce:transform-none',
                                product.second_image_url &&
                                    'md:group-hover:opacity-0',
                            )}
                        />
                        {product.second_image_url && (
                            <img
                                src={product.second_image_url}
                                alt=""
                                aria-hidden="true"
                                loading="lazy"
                                decoding="async"
                                className="absolute inset-0 hidden h-full w-full object-contain p-4 opacity-0 transition duration-500 group-hover:scale-105 group-hover:opacity-100 motion-reduce:transform-none md:block"
                            />
                        )}
                    </>
                ) : (
                    <span className="absolute inset-0 grid place-items-center text-4xl font-black text-brand-text">
                        B
                    </span>
                )}
                <span className="absolute start-2 top-2 flex flex-col items-start gap-1">
                    {product.discount_percent > 0 && (
                        <span className="rounded-full bg-sale px-2 py-0.5 text-xs font-black text-white shadow-sm">
                            {t('store.sale_badge', {
                                percent: product.discount_percent,
                            })}
                        </span>
                    )}
                    {product.is_new && (
                        <span className="rounded-full bg-brand px-2 py-0.5 text-[10px] font-bold text-on-brand shadow-sm">
                            {t('store.new')}
                        </span>
                    )}
                    {product.price_is_estimate && (
                        <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold text-amber-900">
                            {t('store.approximate')}
                        </span>
                    )}
                </span>
            </Link>
            <FavoriteButton
                productId={product.id}
                className="absolute end-2 top-2 z-10 size-9"
            />
            <div className="flex flex-1 flex-col p-3 sm:p-4">
                {product.brand && (
                    <p className="truncate text-xs font-semibold text-ink-soft">
                        {product.brand}
                    </p>
                )}
                <Link href={href} className="mt-0.5">
                    <h3 className="line-clamp-2 min-h-10 text-sm leading-5 font-semibold text-ink transition group-hover:text-brand-text">
                        {product.name}
                    </h3>
                </Link>
                <p className="mt-2 flex flex-wrap items-baseline gap-x-2">
                    <span
                        dir="ltr"
                        className={cn(
                            'text-lg font-black',
                            product.original_price ? 'text-sale' : 'text-ink',
                        )}
                    >
                        {product.price_is_estimate && '≈ '}
                        {formatMoney(product.price, product.currency, 'en')}
                    </span>
                    {product.original_price && (
                        <s
                            dir="ltr"
                            className="text-sm font-semibold text-ink-soft"
                            aria-label={t('store.was_price')}
                        >
                            {formatMoney(
                                product.original_price,
                                product.currency,
                                'en',
                            )}
                        </s>
                    )}
                </p>
                <div className="mt-1 flex min-h-5 items-center justify-between gap-2">
                    <AvailabilityNote product={product} />
                    <ColorDots colors={product.colors} max={4} />
                </div>
                {needsColor && product.purchasable ? (
                    <Link
                        href={href}
                        className="mt-3 inline-flex h-10 w-full items-center justify-center gap-2 rounded-btn border border-brand text-sm font-bold text-brand-text transition hover:bg-brand hover:text-on-brand"
                    >
                        <Palette size={16} aria-hidden="true" />
                        {t('store.choose_color_button')}
                    </Link>
                ) : (
                    <button
                        type="button"
                        disabled={!product.purchasable || adding}
                        onClick={add}
                        className={cn(
                            'mt-3 inline-flex h-10 w-full items-center justify-center gap-2 rounded-btn text-sm font-bold transition disabled:cursor-not-allowed',
                            product.purchasable
                                ? 'bg-brand text-on-brand hover:bg-brand-hover active:scale-95 motion-reduce:transform-none'
                                : 'bg-surface text-ink-soft',
                        )}
                    >
                        {adding ? (
                            <Spinner />
                        ) : added ? (
                            <Check size={16} aria-hidden="true" />
                        ) : (
                            <ShoppingCart size={16} aria-hidden="true" />
                        )}
                        {!product.purchasable
                            ? t('store.out_of_stock')
                            : added
                              ? t('store.added')
                              : t('store.add_to_cart')}
                    </button>
                )}
            </div>
        </article>
    );
}
