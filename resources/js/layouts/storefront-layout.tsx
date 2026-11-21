import { Link, router, usePage } from "@inertiajs/react";
import {
    LayoutGrid,
    LogOut,
    Search,
    ShoppingCart,
    UserRound,
} from "lucide-react";
import type { FormEvent, ReactNode } from "react";
import { useEffect, useState } from "react";
import BrandsMenu from "@/components/store/brands-menu";
import { CompareBar } from "@/components/store/compare";
import { syncFavoritesWith } from "@/hooks/use-favorites";
import ColorModeToggle from "@/components/color-mode-toggle";
import CartDrawer from "@/components/store/cart-drawer";
import FavoritesLink from "@/components/store/favorites-link";
import MobileTabBar from "@/components/store/mobile-tab-bar";
import StaffBar from "@/components/store/staff-bar";
import StoreFooter from "@/components/store/store-footer";
import WhatsAppButton from "@/components/store/whatsapp-button";
import { useTranslations } from "@/hooks/use-translations";
import { formatMoney } from "@/lib/store";
import { cn } from "@/lib/utils";
import type { ProductCard } from "@/types";
import StoreLogo from "@/components/store/store-logo";

const locales = [
    { code: "ar", label: "العربية" },
    { code: "he", label: "עברית" },
    { code: "en", label: "English" },
];

const SUGGEST_DELAY_MS = 250;

function HeaderSearch({ className }: { className?: string }) {
    const { url, props } = usePage();
    const { locale } = props;
    const t = useTranslations();
    const [term, setTerm] = useState(
        () => new URLSearchParams(url.split("?")[1]).get("search") ?? "",
    );
    const [suggestions, setSuggestions] = useState<ProductCard[]>([]);
    const [open, setOpen] = useState(false);

    useEffect(() => {
        if (term.trim().length < 2) {
            setSuggestions([]);

            return;
        }

        const request = new AbortController();
        const timer = window.setTimeout(() => {
            fetch(
                `/${locale}/search/suggest?q=${encodeURIComponent(term.trim())}`,
                {
                    signal: request.signal,
                    headers: { Accept: "application/json" },
                },
            )
                .then((response) => response.json())
                .then((data: { products: ProductCard[] }) =>
                    setSuggestions(data.products),
                )
                .catch(() => undefined);
        }, SUGGEST_DELAY_MS);

        return () => {
            window.clearTimeout(timer);
            request.abort();
        };
    }, [term, locale]);

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setOpen(false);
        router.get(`/${locale}/products`, term ? { search: term } : {});
    }

    return (
        <form
            onSubmit={submit}
            role="search"
            className={className}
            onFocus={() => setOpen(true)}
            onBlur={(event) => {
                if (!event.currentTarget.contains(event.relatedTarget)) {
                    setOpen(false);
                }
            }}
            onKeyDown={(event) => {
                if (event.key === "Escape") {
                    setOpen(false);
                }
            }}
        >
            <div className="relative">
                <input
                    type="search"
                    value={term}
                    onChange={(event) => {
                        setTerm(event.target.value);
                        setOpen(true);
                    }}
                    placeholder={t("store.search")}
                    aria-label={t("store.search")}
                    autoComplete="off"
                    className="h-11 w-full rounded-btn border border-line bg-surface ps-4 pe-12 text-sm text-ink transition outline-none placeholder:text-ink-soft focus:border-brand focus:bg-panel"
                />
                <button
                    type="submit"
                    aria-label={t("store.search_button")}
                    className="absolute end-1 top-1 grid h-9 w-9 place-items-center rounded-full bg-brand text-on-brand transition hover:bg-brand-hover"
                >
                    <Search size={16} aria-hidden="true" />
                </button>

                {open && suggestions.length > 0 && (
                    <ul className="absolute inset-x-0 top-full z-40 mt-2 overflow-hidden rounded-xl border border-line bg-panel shadow-2xl">
                        {suggestions.map((product) => (
                            <li key={product.id}>
                                <Link
                                    href={`/${locale}/products/${product.slug}`}
                                    onClick={() => setOpen(false)}
                                    className="flex items-center gap-3 px-3 py-2 transition hover:bg-surface focus:bg-surface"
                                >
                                    <span className="h-11 w-11 shrink-0 overflow-hidden rounded-lg bg-surface">
                                        {product.image_url && (
                                            <img
                                                src={product.image_url}
                                                alt=""
                                                loading="lazy"
                                                className="h-full w-full object-contain p-1"
                                            />
                                        )}
                                    </span>
                                    <span className="min-w-0 flex-1 truncate text-sm font-semibold text-ink">
                                        {product.name}
                                    </span>
                                    <span
                                        className="shrink-0 text-sm font-black text-ink"
                                        dir="ltr"
                                    >
                                        {formatMoney(
                                            product.price,
                                            product.currency,
                                            "en",
                                        )}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </form>
    );
}

export default function StorefrontLayout({
    children,
}: {
    children: ReactNode;
}) {
    const { auth, cart, footer, locale, direction, themePreview } =
        usePage().props;
    const t = useTranslations();
    const count = cart?.count ?? 0;

    // Favorites follow a signed-in customer to every device.
    useEffect(() => syncFavoritesWith(auth.user?.id ?? null), [auth.user?.id]);

    return (
        <div
            dir={direction}
            className="min-h-screen bg-surface pb-[calc(4rem+env(safe-area-inset-bottom))] text-ink md:pb-0"
        >
            <a
                href="#content"
                className="sr-only focus:not-sr-only focus:fixed focus:start-3 focus:top-3 focus:z-[100] focus:rounded-full focus:bg-brand focus:px-4 focus:py-2 focus:text-sm focus:font-bold focus:text-on-brand focus:shadow-lg"
            >
                {t("nav.skip_to_content")}
            </a>
            <StaffBar />
            <div className="relative z-50 bg-topbar text-xs text-on-topbar">
                <div className="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-1.5 sm:px-6 lg:px-8">
                    <div className="flex min-w-0 items-center gap-3">
                        <BrandsMenu />
                        <span className="hidden truncate lg:inline">
                            {t("nav.tagline")}
                        </span>
                        <nav
                            aria-label={t("service.menu")}
                            className="hidden items-center gap-3 font-semibold md:flex"
                        >
                            <Link
                                href={`/${locale}/repairs`}
                                className="inline-flex min-h-6 items-center opacity-80 hover:opacity-100"
                            >
                                {t("service.repairs_title")}
                            </Link>
                            {footer?.has_sim_plans && (
                                <Link
                                    href={`/${locale}/sim-plans`}
                                    className="inline-flex min-h-6 items-center opacity-80 hover:opacity-100"
                                >
                                    {t("service.sim_title")}
                                </Link>
                            )}
                            <Link
                                href={`/${locale}/contact`}
                                className="inline-flex min-h-6 items-center opacity-80 hover:opacity-100"
                            >
                                {t("service.contact_title")}
                            </Link>
                        </nav>
                    </div>
                    {/* Above the brands panel's backdrop, so they stay usable while it is open. */}
                    <div
                        className="relative z-50 flex items-center gap-3"
                        role="group"
                        aria-label="Language"
                    >
                        {locales.map((item) => (
                            <Link
                                key={item.code}
                                href={`/${item.code}`}
                                aria-current={
                                    locale === item.code ? "page" : undefined
                                }
                                className={cn(
                                    "inline-flex min-h-6 min-w-6 items-center justify-center px-1",
                                    locale === item.code
                                        ? "font-bold underline underline-offset-4"
                                        : "opacity-80 hover:opacity-100",
                                )}
                            >
                                {item.label}
                            </Link>
                        ))}
                        <ColorModeToggle className="grid h-6 w-6 place-items-center rounded-full hover:bg-on-topbar/15" />
                    </div>
                </div>
            </div>

            <header className="site-header sticky top-0 z-30 border-b border-line bg-header/95 backdrop-blur">
                <div className="mx-auto flex max-w-7xl items-center gap-4 px-4 py-3 sm:px-6 lg:gap-6 lg:px-8">
                    <Link
                        href={`/${locale}`}
                        className="text-2xl font-black tracking-tight text-ink"
                    >
                        <StoreLogo />
                    </Link>

                    <Link
                        href={`/${locale}/categories`}
                        className="hidden items-center gap-2 rounded-btn px-3 py-2 text-sm font-bold text-ink transition hover:bg-surface hover:text-brand-text md:inline-flex"
                    >
                        <LayoutGrid size={17} aria-hidden="true" />
                        {t("store.categories_tab")}
                    </Link>

                    <HeaderSearch className="hidden flex-1 md:block" />

                    <div className="ms-auto flex items-center gap-1 text-sm font-semibold">
                        {auth.user ? (
                            <>
                                <Link
                                    href={`/${locale}/account`}
                                    className="hidden items-center gap-2 rounded-btn px-3 py-2 text-ink transition hover:bg-surface hover:text-brand-text md:inline-flex"
                                >
                                    <UserRound size={17} aria-hidden="true" />
                                    {t("nav.account")}
                                </Link>
                                <Link
                                    href="/logout"
                                    method="post"
                                    as="button"
                                    aria-label={t("nav.logout")}
                                    title={t("nav.logout")}
                                    className="hidden h-9 w-9 cursor-pointer place-items-center rounded-full text-ink-soft transition hover:bg-surface hover:text-brand-text md:grid"
                                >
                                    <LogOut
                                        size={16}
                                        aria-hidden="true"
                                        className="rtl:rotate-180"
                                    />
                                </Link>
                            </>
                        ) : (
                            <>
                                <Link
                                    href="/login"
                                    className="hidden rounded-btn px-3 py-2 text-ink transition hover:text-brand-text md:inline-block"
                                >
                                    {t("nav.login")}
                                </Link>
                                <Link
                                    href="/register"
                                    className="hidden rounded-btn bg-brand px-4 py-2 text-on-brand transition hover:bg-brand-hover lg:inline-block"
                                >
                                    {t("nav.register")}
                                </Link>
                            </>
                        )}
                        <FavoritesLink />
                        <Link
                            href={`/${locale}/cart`}
                            aria-label={`${t("store.cart")} (${count})`}
                            data-cart-target
                            className="relative hidden h-10 w-10 place-items-center rounded-full text-ink transition hover:bg-surface hover:text-brand-text md:grid"
                        >
                            <ShoppingCart size={20} aria-hidden="true" />
                            {count > 0 && (
                                <span
                                    key={count}
                                    className="badge-pop absolute -end-0.5 -top-0.5 grid h-5 min-w-5 place-items-center rounded-full bg-brand px-1 text-[11px] font-bold text-on-brand"
                                >
                                    {count > 9 ? "9+" : count}
                                </span>
                            )}
                        </Link>
                    </div>
                </div>
                <div className="px-4 pb-3 md:hidden">
                    <HeaderSearch />
                </div>
            </header>

            <div id="content" tabIndex={-1} className="outline-none">
                {children}
            </div>

            <StoreFooter />
            <CompareBar />
            <WhatsAppButton />
            <MobileTabBar />
            <CartDrawer />

            {themePreview && (
                <div
                    role="status"
                    className="fixed inset-x-4 bottom-20 z-50 mx-auto flex max-w-xl flex-wrap items-center justify-between gap-3 rounded-xl bg-ink px-4 py-3 text-sm font-semibold text-panel shadow-xl md:bottom-4"
                >
                    <span>{t("nav.preview_banner")}</span>
                    <a href="/admin/theme/preview/exit" className="underline">
                        {t("nav.preview_back")}
                    </a>
                </div>
            )}
        </div>
    );
}
