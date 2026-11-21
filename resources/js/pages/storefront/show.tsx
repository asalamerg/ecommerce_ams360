import { Link, usePage } from '@inertiajs/react';
import { ShoppingCart, Truck, Undo2, Zap } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { ColorPicker } from '@/components/store/color-dots';
import Countdown from '@/components/store/countdown';
import FavoriteButton from '@/components/store/favorite-button';
import { CompareButton } from '@/components/store/compare';
import { ProductWhatsAppLink } from '@/components/store/whatsapp-button';
import ProductCard, { AvailabilityNote } from '@/components/store/product-card';
import {
    NotifyWhenAvailable,
    ProductReviews,
    Stars,
} from '@/components/store/product-reviews';
import type { ReviewsData } from '@/components/store/product-reviews';
import QuantityStepper from '@/components/store/quantity-stepper';
import Rail from '@/components/store/rail';
import RecentlyViewed, {
    rememberViewed,
} from '@/components/store/recently-viewed';
import SeoTitle from '@/components/store/seo-title';
import { Spinner } from '@/components/ui/spinner';
import { addToCart } from '@/hooks/use-cart';
import { useTranslations } from '@/hooks/use-translations';
import StorefrontLayout from '@/layouts/storefront-layout';
import { formatMoney } from '@/lib/store';
import { cn } from '@/lib/utils';
import type { ProductCard as Card, OfferCard } from '@/types';

type Product = Card & {
    sku: string | null;
    images: string[];
    description: string | null;
    seo_title: string | null;
    seo_description: string | null;
    category_slug: string | null;
    offer: OfferCard | null;
};

export default function StorefrontProduct({
    product,
    related,
    reviews,
    notifyWhenAvailable,
    turnstileSiteKey,
    policies,
}: {
    product: Product;
    related: Card[];
    reviews: ReviewsData;
    notifyWhenAvailable: boolean;
    turnstileSiteKey: string | null;
    policies: { delivery: string | null; returns: string | null };
}) {
    const { locale } = usePage().props;

    useEffect(() => rememberViewed(product.id), [product.id]);
    const t = useTranslations();
    const gallery = useRef<HTMLDivElement>(null);
    const mainImage = useRef<HTMLImageElement>(null);
    const [active, setActive] = useState(0);
    const [quantity, setQuantity] = useState(1);
    const [busy, setBusy] = useState<'add' | 'buy' | null>(null);
    // One color is picked for the customer when there is only one.
    const [colorId, setColorId] = useState<number | null>(
        product.colors.length === 1 ? product.colors[0].id : null,
    );
    const [colorMissing, setColorMissing] = useState(false);
    const price = formatMoney(product.price, product.currency, 'en');

    function add(mode: 'add' | 'buy') {
        if (product.colors.length > 0 && colorId === null) {
            setColorMissing(true);
            document
                .getElementById('product-colors')
                ?.scrollIntoView({ behavior: 'smooth', block: 'center' });

            return;
        }

        setBusy(mode);
        addToCart(product.id, quantity, {
            image: mainImage.current,
            colorId,
            goToCart: mode === 'buy' ? `/${locale}/cart` : undefined,
            onFinish: () => setBusy(null),
        });
    }

    /** Thumbnails and swipes share the same strip, so they cannot disagree. */
    function showImage(index: number) {
        const strip = gallery.current;
        const slide = strip?.children[index] as HTMLElement | undefined;

        setActive(index);
        slide?.scrollIntoView({
            behavior: 'smooth',
            block: 'nearest',
            inline: 'center',
        });
    }

    function trackSwipe() {
        const strip = gallery.current;

        if (strip) {
            setActive(
                Math.round(Math.abs(strip.scrollLeft) / strip.clientWidth),
            );
        }
    }

    return (
        <StorefrontLayout>
            <SeoTitle />
            <main className="mx-auto max-w-7xl px-4 py-4 sm:px-6 sm:py-8 lg:px-8">
                <nav
                    aria-label={t('store.home')}
                    className="mb-4 flex flex-wrap items-center gap-x-2 text-sm"
                >
                    <Link
                        href={`/${locale}`}
                        className="text-ink-soft hover:text-brand-text"
                    >
                        {t('store.home')}
                    </Link>
                    {product.category && product.category_slug && (
                        <>
                            <span className="text-ink-soft">/</span>
                            <Link
                                href={`/${locale}/categories/${product.category_slug}`}
                                className="text-ink-soft hover:text-brand-text"
                            >
                                {product.category}
                            </Link>
                        </>
                    )}
                </nav>

                <article className="grid gap-6 rounded-panel border border-line bg-panel p-4 sm:p-8 lg:grid-cols-2 lg:gap-12 lg:p-10">
                    <div className="min-w-0">
                        <div
                            ref={gallery}
                            onScroll={trackSwipe}
                            className="flex snap-x snap-mandatory scrollbar-none overflow-x-auto rounded-xl bg-surface"
                        >
                            {(product.images.length
                                ? product.images
                                : [null]
                            ).map((image, index) => (
                                <div
                                    key={image ?? 'empty'}
                                    className="aspect-square w-full shrink-0 snap-center"
                                >
                                    {image ? (
                                        <img
                                            ref={
                                                index === 0
                                                    ? mainImage
                                                    : undefined
                                            }
                                            src={image}
                                            alt={product.name}
                                            fetchPriority={
                                                index === 0 ? 'high' : undefined
                                            }
                                            loading={
                                                index === 0 ? undefined : 'lazy'
                                            }
                                            className="h-full w-full object-contain p-6 sm:p-10"
                                        />
                                    ) : (
                                        <span className="grid h-full place-items-center text-7xl font-black text-brand-text">
                                            B
                                        </span>
                                    )}
                                </div>
                            ))}
                        </div>
                        {product.images.length > 1 && (
                            <div className="mt-3 flex gap-2 overflow-x-auto">
                                {product.images.map((image, index) => (
                                    <button
                                        type="button"
                                        key={image}
                                        onClick={() => showImage(index)}
                                        aria-label={`${product.name} ${index + 1}`}
                                        aria-pressed={active === index}
                                        className={cn(
                                            'h-16 w-16 shrink-0 overflow-hidden rounded-lg border-2 bg-surface transition hover:border-brand',
                                            active === index
                                                ? 'border-brand'
                                                : 'border-transparent',
                                        )}
                                    >
                                        <img
                                            src={image}
                                            alt=""
                                            loading="lazy"
                                            decoding="async"
                                            className="h-full w-full object-contain p-1"
                                        />
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>

                    <div className="flex min-w-0 flex-col">
                        {product.brand &&
                            (product.brand_slug ? (
                                <Link
                                    href={`/${locale}/brands/${product.brand_slug}`}
                                    className="w-fit text-sm font-bold text-brand-text hover:underline"
                                >
                                    {product.brand}
                                </Link>
                            ) : (
                                <p className="text-sm font-bold text-brand-text">
                                    {product.brand}
                                </p>
                            ))}
                        <div className="mt-1 flex items-start justify-between gap-3">
                            <h1 className="text-2xl leading-tight font-black sm:text-3xl">
                                {product.name}
                            </h1>
                            <FavoriteButton
                                productId={product.id}
                                size={20}
                                className="size-11 shrink-0"
                            />
                        </div>
                        <p className="mt-4 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <span
                                dir="ltr"
                                className={cn(
                                    'text-3xl font-black',
                                    product.original_price
                                        ? 'text-sale'
                                        : 'text-ink',
                                )}
                            >
                                {product.price_is_estimate && '≈ '}
                                {price}
                            </span>
                            {product.original_price && (
                                <s
                                    dir="ltr"
                                    className="text-lg font-bold text-ink-soft"
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
                        {product.original_price && (
                            <div className="mt-2 flex flex-wrap items-center gap-2 text-sm font-bold">
                                <span className="rounded-full bg-sale px-3 py-1 text-white">
                                    {t('store.sale_badge', {
                                        percent: product.discount_percent,
                                    })}
                                </span>
                                <span className="text-sale">
                                    {t('store.you_save', {
                                        amount: formatMoney(
                                            Number(product.original_price) -
                                                Number(product.price),
                                            product.currency,
                                            'en',
                                        ),
                                    })}
                                </span>
                                {product.offer && (
                                    <Link
                                        href={`/${locale}/offers/${product.offer.id}`}
                                        className="font-bold text-brand-text hover:underline"
                                    >
                                        {product.offer.title}
                                    </Link>
                                )}
                                {product.discount_ends_at && (
                                    <Countdown
                                        endsAt={product.discount_ends_at}
                                        className="w-full text-sale"
                                    />
                                )}
                            </div>
                        )}
                        {product.price_is_estimate && (
                            <span className="mt-2 w-fit rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-900">
                                {t('store.approximate')}
                            </span>
                        )}
                        <div className="mt-3">
                            <AvailabilityNote product={product} />
                        </div>
                        {product.sku && (
                            <p className="mt-2 text-sm text-ink-soft">
                                {t('store.sku')}: {product.sku}
                            </p>
                        )}

                        {product.colors.length > 0 && (
                            <div id="product-colors" className="mt-5">
                                <ColorPicker
                                    colors={product.colors}
                                    value={colorId}
                                    onChange={(id) => {
                                        setColorId(id);
                                        setColorMissing(false);
                                    }}
                                    error={colorMissing}
                                />
                            </div>
                        )}

                        {product.purchasable && (
                            <div className="mt-5">
                                <span className="mb-1.5 block text-sm font-semibold">
                                    {t('store.quantity')}
                                </span>
                                <QuantityStepper
                                    value={quantity}
                                    max={product.max_quantity}
                                    onChange={setQuantity}
                                />
                            </div>
                        )}

                        <div className="fixed inset-x-0 bottom-[calc(4rem+env(safe-area-inset-bottom))] z-30 border-t border-line bg-panel/95 p-3 backdrop-blur md:static md:mt-5 md:border-0 md:bg-transparent md:p-0 md:backdrop-blur-none">
                            <div className="flex gap-2">
                                <button
                                    type="button"
                                    disabled={!product.purchasable || !!busy}
                                    onClick={() => add('add')}
                                    className="inline-flex h-12 flex-1 items-center justify-center gap-2 rounded-btn bg-brand font-bold text-on-brand transition hover:bg-brand-hover active:scale-95 disabled:cursor-not-allowed disabled:opacity-50 motion-reduce:transform-none"
                                >
                                    {busy === 'add' ? (
                                        <Spinner />
                                    ) : (
                                        <ShoppingCart
                                            size={18}
                                            aria-hidden="true"
                                        />
                                    )}
                                    {product.purchasable
                                        ? t('store.add_to_cart')
                                        : t('store.out_of_stock')}
                                </button>
                                {product.purchasable && (
                                    <button
                                        type="button"
                                        disabled={!!busy}
                                        onClick={() => add('buy')}
                                        className="inline-flex h-12 items-center justify-center gap-2 rounded-btn border border-ink px-4 font-bold text-ink transition hover:bg-ink hover:text-panel disabled:opacity-50 sm:px-6"
                                    >
                                        {busy === 'buy' ? (
                                            <Spinner />
                                        ) : (
                                            <Zap size={17} aria-hidden="true" />
                                        )}
                                        {t('store.buy_now')}
                                    </button>
                                )}
                            </div>
                        </div>

                        <div className="mt-4 flex flex-wrap items-center gap-2">
                            <CompareButton productId={product.id} />
                            <ProductWhatsAppLink />
                        </div>
                        {notifyWhenAvailable && (
                            <NotifyWhenAvailable
                                productId={product.id}
                                turnstileSiteKey={turnstileSiteKey}
                            />
                        )}
                        {reviews.count > 0 && (
                            <a
                                href="#reviews"
                                className="mt-4 inline-flex items-center gap-2 text-sm text-ink-soft hover:text-brand-text"
                            >
                                <Stars value={reviews.average} size={15} />
                                {t('reviews.summary', {
                                    average: reviews.average,
                                    count: reviews.count,
                                })}
                            </a>
                        )}

                        {product.description && (
                            <section className="mt-6 border-t border-line pt-5">
                                <h2 className="font-bold">
                                    {t('store.description')}
                                </h2>
                                <p className="mt-2 leading-7 whitespace-pre-line text-ink-soft">
                                    {product.description}
                                </p>
                            </section>
                        )}

                        {(policies.delivery || policies.returns) && (
                            <div className="mt-6 grid gap-2 border-t border-line pt-5">
                                {[
                                    {
                                        key: 'delivery',
                                        icon: Truck,
                                        title: t('service.delivery_policy'),
                                        text: policies.delivery,
                                    },
                                    {
                                        key: 'returns',
                                        icon: Undo2,
                                        title: t('service.return_policy'),
                                        text: policies.returns,
                                    },
                                ]
                                    .filter((item) => item.text)
                                    .map(({ key, icon: Icon, title, text }) => (
                                        <details
                                            key={key}
                                            className="rounded-xl border border-line px-4 py-3"
                                        >
                                            <summary className="flex cursor-pointer items-center gap-2 text-sm font-bold">
                                                <Icon
                                                    size={17}
                                                    aria-hidden="true"
                                                />
                                                {title}
                                            </summary>
                                            <p className="mt-2 text-sm leading-6 whitespace-pre-line text-ink-soft">
                                                {text}
                                            </p>
                                        </details>
                                    ))}
                            </div>
                        )}
                    </div>
                </article>

                <ProductReviews productId={product.id} reviews={reviews} />

                {related.length > 0 && (
                    <section aria-labelledby="related" className="mt-10">
                        <h2 id="related" className="mb-4 text-xl font-black">
                            {t('store.related')}
                        </h2>
                        <Rail label={t('store.related')}>
                            {related.map((item) => (
                                <ProductCard
                                    key={item.id}
                                    product={item}
                                    className="w-44 shrink-0 snap-start sm:w-52 lg:w-56"
                                />
                            ))}
                        </Rail>
                    </section>
                )}
                <RecentlyViewed exclude={product.id} />
                <div className="h-20 md:hidden" aria-hidden="true" />
            </main>
        </StorefrontLayout>
    );
}
