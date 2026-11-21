import { Link, router, usePage } from '@inertiajs/react';
import { ChevronDown, Search, Tags, X } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import BrandTile from '@/components/store/brand-tile';
import type { BrandCard } from '@/components/store/brand-tile';
import { useTranslations } from '@/hooks/use-translations';
import { fold } from '@/lib/fold-text';
import { cn } from '@/lib/utils';

// Loaded once per language and visit: the list is the same on every page.
const loaded = new Map<string, BrandCard[]>();

/**
 * "Brands" in the topbar. A click opens a panel of brand pictures with a
 * search box; the list is fetched on first open only, so the pictures never
 * slow down pages where nobody opens it. Escape, the close button, a click
 * outside or opening a brand's page closes it; pointing at a brand does not.
 */
export default function BrandsMenu() {
    const { locale } = usePage().props;
    const t = useTranslations();
    const panelId = useId();
    const search = useRef<HTMLInputElement>(null);
    const [open, setOpen] = useState(false);
    const [brands, setBrands] = useState<BrandCard[] | null>(
        () => loaded.get(locale) ?? null,
    );
    const [failed, setFailed] = useState(false);
    const [query, setQuery] = useState('');

    useEffect(() => {
        if (!open || brands !== null) {
            return;
        }

        const controller = new AbortController();
        fetch(`/${locale}/brands/menu`, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(String(response.status));
                }

                return response.json() as Promise<{ brands: BrandCard[] }>;
            })
            .then((data) => {
                loaded.set(locale, data.brands);
                setBrands(data.brands);
            })
            .catch((error: unknown) => {
                if (!(error instanceof DOMException)) {
                    setFailed(true);
                }
            });

        return () => controller.abort();
    }, [open, brands, locale]);

    useEffect(() => {
        if (!open) {
            return;
        }

        search.current?.focus({ preventScroll: true });
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        };
        // "navigate" fires only when a new page is shown. "start" also fires for
        // the hover prefetch of each brand tile, which closed the panel as soon
        // as the mouse moved over a brand.
        const stop = router.on('navigate', () => setOpen(false));
        window.addEventListener('keydown', onKey);

        return () => {
            window.removeEventListener('keydown', onKey);
            stop();
        };
    }, [open]);

    const term = fold(query.trim());
    const shown = (brands ?? []).filter(
        (brand) =>
            !term || fold(`${brand.name} ${brand.keywords}`).includes(term),
    );

    return (
        <>
            <button
                type="button"
                aria-expanded={open}
                aria-controls={panelId}
                onClick={() => setOpen((value) => !value)}
                className="relative z-50 inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-0.5 font-bold transition hover:bg-on-topbar/15"
            >
                <Tags size={14} aria-hidden="true" />
                {t('brands.title')}
                <ChevronDown
                    size={13}
                    aria-hidden="true"
                    className={cn(
                        'transition-transform motion-reduce:transition-none',
                        open && 'rotate-180',
                    )}
                />
            </button>

            {open && (
                <>
                    <div
                        aria-hidden="true"
                        onClick={() => setOpen(false)}
                        className="fixed inset-0 z-40 bg-black/30 backdrop-blur-[1px]"
                    />
                    <section
                        id={panelId}
                        aria-label={t('brands.title')}
                        className="absolute inset-x-0 top-full z-50 border-b border-line bg-panel text-ink shadow-2xl"
                    >
                        <div className="mx-auto max-w-7xl px-4 py-4 sm:px-6 lg:px-8">
                            <div className="mb-4 flex flex-wrap items-center gap-3">
                                <h2 className="text-lg font-black">
                                    {t('brands.title')}
                                </h2>
                                <label className="relative order-last w-full sm:order-none sm:ms-auto sm:w-64">
                                    <Search
                                        size={15}
                                        aria-hidden="true"
                                        className="pointer-events-none absolute start-3 top-1/2 -translate-y-1/2 text-ink-soft"
                                    />
                                    <input
                                        ref={search}
                                        type="search"
                                        value={query}
                                        onChange={(event) =>
                                            setQuery(event.target.value)
                                        }
                                        placeholder={t('brands.search')}
                                        aria-label={t('brands.search')}
                                        className="admin-input ps-9 text-sm"
                                    />
                                </label>
                                <Link
                                    href={`/${locale}/brands`}
                                    className="ms-auto text-sm font-bold text-brand-text hover:underline sm:ms-0"
                                >
                                    {t('brands.all')}
                                </Link>
                                <button
                                    type="button"
                                    onClick={() => setOpen(false)}
                                    aria-label={t('brands.close')}
                                    className="grid h-8 w-8 place-items-center rounded-full hover:bg-surface"
                                >
                                    <X size={17} aria-hidden="true" />
                                </button>
                            </div>

                            <div className="max-h-[min(65vh,34rem)] overflow-y-auto overscroll-contain pb-1">
                                {failed ? (
                                    <p className="py-8 text-center text-sm text-ink-soft">
                                        {t('brands.failed')}
                                    </p>
                                ) : brands === null ? (
                                    <div className="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-6 lg:grid-cols-8">
                                        {Array.from({ length: 16 }, (_, i) => (
                                            <div
                                                key={i}
                                                className="aspect-[4/5] animate-pulse rounded-panel bg-surface motion-reduce:animate-none"
                                            />
                                        ))}
                                    </div>
                                ) : shown.length ? (
                                    <ul className="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-6 lg:grid-cols-8">
                                        {shown.map((brand) => (
                                            <li key={brand.slug}>
                                                <BrandTile
                                                    brand={brand}
                                                    compact
                                                    onClick={() =>
                                                        setOpen(false)
                                                    }
                                                />
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="py-8 text-center text-sm text-ink-soft">
                                        {t('brands.no_match')}
                                    </p>
                                )}
                            </div>
                        </div>
                    </section>
                </>
            )}
        </>
    );
}
