import { Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Award,
    BadgePercent,
    CreditCard,
    Headset,
    RotateCcw,
    ShieldCheck,
    Sparkles,
    Store,
    Truck,
    Wrench,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useState } from 'react';
import BrandTile from '@/components/store/brand-tile';
import OfferBanner from '@/components/store/offer-banner';
import type { BrandCard } from '@/components/store/brand-tile';
import ProductCard from '@/components/store/product-card';
import Rail from '@/components/store/rail';
import RecentlyViewed from '@/components/store/recently-viewed';
import Reveal from '@/components/store/reveal';
import SeoTitle from '@/components/store/seo-title';
import { useTranslations } from '@/hooks/use-translations';
import StorefrontLayout from '@/layouts/storefront-layout';
import { cn } from '@/lib/utils';
import type { CategoryCard, OfferCard, ProductCard as Product } from '@/types';

type Slide = {
    image_url: string;
    title: string | null;
    subtitle: string | null;
    link_url: string | null;
};
type RailCategory = CategoryCard & { products: Product[] };
type Service = {
    title: string;
    description: string | null;
    icon: string | null;
};
type Props = {
    brands: BrandCard[];
    slides: Slide[];
    offers: OfferCard[];
    categories: CategoryCard[];
    latest: Product[];
    deals: Product[];
    featured: Product[];
    rails: RailCategory[];
    services: Service[];
};

const SLIDE_MS = 6000;

/** The icon choices of a service, see StoreAdminModules::SERVICE_ICONS. */
const serviceIcons: Record<string, LucideIcon> = {
    shield: ShieldCheck,
    truck: Truck,
    wrench: Wrench,
    card: CreditCard,
    headset: Headset,
    returns: RotateCcw,
    store: Store,
    award: Award,
};

const railCard = 'w-44 shrink-0 snap-start sm:w-52 lg:w-56';

function Hero({ slides, products }: { slides: Slide[]; products: Product[] }) {
    const { locale, direction } = usePage().props;
    const t = useTranslations();
    const [active, setActive] = useState(0);
    // The first picture is the page's main content, so it shows at once; only
    // later slides get the entrance animation.
    const [rotated, setRotated] = useState(false);
    const [paused, setPaused] = useState(false);
    const ArrowIcon = direction === 'rtl' ? ArrowLeft : ArrowRight;

    // Without admin slides the hero shows real products, so it is never empty.
    const frames = slides.length
        ? slides.map((slide) => ({
              image: slide.image_url,
              title: slide.title,
              subtitle: slide.subtitle,
              href: slide.link_url ?? `/${locale}/products`,
          }))
        : products
              .filter((product) => product.image_url)
              .slice(0, 4)
              .map((product) => ({
                  image: product.image_url as string,
                  title: null,
                  subtitle: null,
                  href: `/${locale}/products/${product.slug}`,
              }));

    useEffect(() => {
        const moves =
            frames.length > 1 &&
            !paused &&
            window.matchMedia('(hover: hover) and (min-width: 1024px)')
                .matches &&
            !window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        if (!moves) {
            return;
        }

        const timer = window.setInterval(() => {
            setRotated(true);
            setActive((current) => (current + 1) % frames.length);
        }, SLIDE_MS);

        return () => window.clearInterval(timer);
    }, [frames.length, paused]);

    const frame = frames[active];

    return (
        <section
            onMouseEnter={() => setPaused(true)}
            onMouseLeave={() => setPaused(false)}
            className="relative isolate overflow-hidden bg-topbar text-on-topbar"
        >
            <div className="absolute inset-0 -z-10 bg-gradient-to-br from-topbar via-topbar to-brand/30" />
            <div className="absolute -end-24 -top-32 -z-10 h-96 w-96 animate-pulse rounded-full bg-brand/20 blur-3xl [animation-duration:6s] motion-reduce:animate-none" />
            <div className="mx-auto grid max-w-7xl items-center gap-6 px-4 py-8 sm:px-6 sm:py-14 lg:grid-cols-[1.15fr_0.85fr] lg:gap-10 lg:px-8 lg:py-20">
                <div>
                    <p className="mb-3 text-sm font-bold text-on-topbar/80">
                        {t('store.hero_eyebrow')}
                    </p>
                    <h1 className="text-3xl leading-tight font-black sm:text-5xl lg:text-6xl">
                        {frame?.title || t('store.hero_title')}
                    </h1>
                    <p className="mt-4 hidden max-w-xl text-lg leading-7 opacity-80 sm:block">
                        {frame?.subtitle || t('store.hero_subtitle')}
                    </p>
                    <div className="mt-6 flex flex-wrap gap-3">
                        <Link
                            href={`/${locale}/products`}
                            className="inline-flex items-center gap-2 rounded-btn bg-brand px-6 py-3 font-bold text-on-brand shadow-lg transition hover:bg-brand-hover active:scale-95 motion-reduce:transform-none"
                        >
                            {t('store.shop_now')}
                            <ArrowIcon size={18} aria-hidden="true" />
                        </Link>
                        <Link
                            href={`/${locale}/categories`}
                            className="inline-flex items-center rounded-btn border border-on-topbar/25 px-6 py-3 font-bold transition hover:bg-on-topbar/10"
                        >
                            {t('store.browse_categories')}
                        </Link>
                    </div>
                </div>

                {frame && (
                    <div>
                        <Link
                            href={frame.href}
                            aria-label={t('store.shop_now')}
                            className="relative block aspect-[2/1] overflow-hidden rounded-panel border border-on-topbar/10 bg-white/95 shadow-2xl sm:aspect-[4/3] lg:aspect-square"
                        >
                            <img
                                key={frame.image}
                                src={frame.image}
                                alt=""
                                fetchPriority="high"
                                className={cn(
                                    'h-full w-full object-contain p-3 sm:p-6',
                                    rotated &&
                                        'animate-in duration-500 zoom-in-95 fade-in motion-reduce:animate-none',
                                )}
                            />
                        </Link>
                        {frames.length > 1 && (
                            <div className="mt-3 flex justify-center gap-2">
                                {frames.map((item, index) => (
                                    <button
                                        key={`${item.image}-${index}`}
                                        type="button"
                                        aria-label={`${index + 1}`}
                                        aria-pressed={active === index}
                                        onClick={() => {
                                            setRotated(true);
                                            setActive(index);
                                        }}
                                        className={cn(
                                            'h-2 rounded-full transition-all',
                                            active === index
                                                ? 'w-7 bg-brand'
                                                : 'w-2 bg-on-topbar/40',
                                        )}
                                    />
                                ))}
                            </div>
                        )}
                    </div>
                )}
            </div>
        </section>
    );
}

function SectionHeading({
    title,
    href,
    id,
    icon,
    note,
}: {
    title: string;
    href?: string;
    id: string;
    /** A category's picture or an icon, before the title. */
    icon?: ReactNode;
    note?: string;
}) {
    const t = useTranslations();

    return (
        <div className="mb-4 flex items-end justify-between gap-4">
            <div className="flex min-w-0 items-center gap-3">
                {icon}
                <div className="min-w-0">
                    <h2
                        id={id}
                        className="truncate text-xl font-black text-ink sm:text-2xl"
                    >
                        {title}
                    </h2>
                    {note && (
                        <p className="text-xs font-semibold text-ink-soft sm:text-sm">
                            {note}
                        </p>
                    )}
                </div>
            </div>
            {href && (
                <Link
                    href={href}
                    className="shrink-0 text-sm font-bold text-brand-text hover:underline"
                >
                    {t('store.view_all')}
                </Link>
            )}
        </div>
    );
}

function ProductRail({
    id,
    title,
    href,
    products,
    icon,
    note,
    tone,
}: {
    id: string;
    title: string;
    href: string;
    products: Product[];
    icon?: ReactNode;
    note?: string;
    /** "deals" sets the row on a tinted band so offers stand out. */
    tone?: 'deals';
}) {
    return (
        <Reveal>
            <section
                aria-labelledby={id}
                className={cn(
                    'mt-10 sm:mt-14',
                    tone === 'deals' &&
                        'rounded-panel bg-sale/8 px-3 pt-5 pb-3 ring-1 ring-sale/20 sm:px-5',
                )}
            >
                <SectionHeading
                    id={id}
                    title={title}
                    href={href}
                    icon={icon}
                    note={note}
                />
                <Rail label={title} autoAdvance>
                    {products.map((product) => (
                        <ProductCard
                            key={product.id}
                            product={product}
                            className={railCard}
                        />
                    ))}
                </Rail>
            </section>
        </Reveal>
    );
}

function SectionIcon({ children }: { children: ReactNode }) {
    return (
        <span className="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-sale text-white shadow-sm">
            {children}
        </span>
    );
}

export default function StorefrontHome({
    brands,
    slides,
    offers,
    categories,
    latest,
    deals,
    featured,
    rails,
    services,
}: Props) {
    const { locale } = usePage().props;
    const t = useTranslations();

    return (
        <StorefrontLayout>
            <SeoTitle />
            <main>
                <Hero slides={slides} products={latest} />

                <div className="mx-auto max-w-7xl px-4 pt-8 sm:px-6 sm:pt-12 lg:px-8">
                    {services.length > 0 && (
                        <Reveal>
                            <ul className="mb-8 grid grid-cols-2 gap-3 lg:grid-cols-4">
                                {services.map((service) => {
                                    const Icon =
                                        serviceIcons[service.icon ?? ''] ??
                                        ShieldCheck;

                                    return (
                                        <li
                                            key={service.title}
                                            className="flex items-center gap-3 rounded-panel border border-line bg-panel p-3"
                                        >
                                            <span className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-brand/10 text-brand-text">
                                                <Icon
                                                    size={19}
                                                    aria-hidden="true"
                                                />
                                            </span>
                                            <span className="min-w-0">
                                                <span className="block text-sm font-bold">
                                                    {service.title}
                                                </span>
                                                {service.description && (
                                                    <span className="mt-0.5 line-clamp-2 block text-xs text-ink-soft">
                                                        {service.description}
                                                    </span>
                                                )}
                                            </span>
                                        </li>
                                    );
                                })}
                            </ul>
                        </Reveal>
                    )}

                    {categories.length > 0 && (
                        <section aria-labelledby="home-categories">
                            <SectionHeading
                                id="home-categories"
                                title={t('store.categories')}
                                href={`/${locale}/categories`}
                            />
                            <Rail label={t('store.categories')}>
                                {categories.map((category, index) => (
                                    <Reveal
                                        key={category.slug}
                                        delay={Math.min(index, 8) * 60}
                                        className="w-24 shrink-0 snap-start sm:w-28"
                                    >
                                        <Link
                                            href={`/${locale}/categories/${category.slug}`}
                                            className="group block text-center"
                                        >
                                            <span className="mx-auto block aspect-square w-full overflow-hidden rounded-full border-2 border-line bg-panel p-2 transition duration-300 group-hover:-translate-y-1 group-hover:border-brand group-hover:shadow-lg motion-reduce:transform-none">
                                                {category.image_url && (
                                                    <img
                                                        src={category.image_url}
                                                        alt=""
                                                        loading="lazy"
                                                        decoding="async"
                                                        className="h-full w-full rounded-full object-contain transition duration-300 group-hover:scale-110 motion-reduce:transform-none"
                                                    />
                                                )}
                                            </span>
                                            <span className="mt-2 line-clamp-2 block text-xs leading-4 font-bold text-ink group-hover:text-brand-text sm:text-sm">
                                                {category.name}
                                            </span>
                                        </Link>
                                    </Reveal>
                                ))}
                            </Rail>
                        </section>
                    )}

                    {brands.length > 0 && (
                        <section
                            aria-labelledby="home-brands"
                            className="mt-10 sm:mt-14"
                        >
                            <SectionHeading
                                id="home-brands"
                                title={t('brands.shop_by')}
                                href={`/${locale}/brands`}
                            />
                            <Rail label={t('brands.shop_by')}>
                                {brands.map((brand, index) => (
                                    <Reveal
                                        key={brand.slug}
                                        delay={Math.min(index, 8) * 50}
                                        className="w-32 shrink-0 snap-start sm:w-36"
                                    >
                                        <BrandTile brand={brand} compact />
                                    </Reveal>
                                ))}
                            </Rail>
                        </section>
                    )}

                    {offers.length > 0 && (
                        <section
                            aria-labelledby="home-offers"
                            className="mt-10 sm:mt-14"
                        >
                            <SectionHeading
                                id="home-offers"
                                title={t('store.offers')}
                            />
                            <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                                {offers.map((offer) => (
                                    <Reveal key={offer.id}>
                                        <OfferBanner offer={offer} />
                                    </Reveal>
                                ))}
                            </div>
                        </section>
                    )}

                    {deals.length > 0 && (
                        <ProductRail
                            id="home-deals"
                            tone="deals"
                            title={t('store.deals')}
                            href={`/${locale}/products?sale=1`}
                            products={deals}
                            icon={
                                <SectionIcon>
                                    <BadgePercent
                                        size={22}
                                        aria-hidden="true"
                                    />
                                </SectionIcon>
                            }
                        />
                    )}

                    {featured.length > 0 && (
                        <ProductRail
                            id="home-featured"
                            title={t('store.featured')}
                            href={`/${locale}/products?featured=1`}
                            products={featured}
                            icon={
                                <span className="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-brand text-on-brand shadow-sm">
                                    <Sparkles size={20} aria-hidden="true" />
                                </span>
                            }
                        />
                    )}

                    {latest.length > 0 && (
                        <ProductRail
                            id="home-latest"
                            title={t('store.latest')}
                            href={`/${locale}/products`}
                            products={latest}
                        />
                    )}

                    {rails.map((rail) => (
                        <ProductRail
                            key={rail.slug}
                            id={`home-${rail.slug}`}
                            title={rail.name}
                            href={`/${locale}/categories/${rail.slug}`}
                            products={rail.products}
                            note={t('store.products_count', {
                                count: rail.count,
                            })}
                            icon={
                                rail.image_url ? (
                                    <img
                                        src={rail.image_url}
                                        alt=""
                                        loading="lazy"
                                        decoding="async"
                                        className="h-11 w-11 shrink-0 rounded-full border border-line bg-panel object-contain p-1"
                                    />
                                ) : undefined
                            }
                        />
                    ))}

                    <RecentlyViewed />
                </div>
            </main>
        </StorefrontLayout>
    );
}
