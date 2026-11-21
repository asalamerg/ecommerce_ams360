import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Bell,
    BookOpen,
    Boxes,
    ChevronDown,
    ClipboardList,
    FileText,
    FolderTree,
    Gift,
    Globe,
    Image,
    LayoutDashboard,
    LifeBuoy,
    LogOut,
    MapPin,
    Megaphone,
    Menu,
    MessageSquare,
    Package,
    Paintbrush,
    Percent,
    PieChart,
    Search,
    Settings,
    ShieldCheck,
    ShoppingBag,
    Sparkles,
    Smartphone,
    Star,
    Store,
    Tag,
    Truck,
    Users,
    Wrench,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import ColorModeToggle from '@/components/color-mode-toggle';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAdminText } from '@/hooks/use-admin-text';
import { cn } from '@/lib/utils';
import type { AdminNavItem } from '@/types';

function timeAgo(iso: string, locale: string): string {
    const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
    const format = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });
    const steps: [Intl.RelativeTimeFormatUnit, number][] = [
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
    ];

    for (const [unit, size] of steps) {
        if (Math.abs(seconds) >= size) {
            return format.format(Math.round(seconds / size), unit);
        }
    }

    return format.format(0, 'minute');
}

const icons: Record<string, LucideIcon> = {
    activity: ClipboardList,
    branches: MapPin,
    brands: Tag,
    shipping: Truck,
    categories: FolderTree,
    contact: MessageSquare,
    featured: Sparkles,
    coupons: Percent,
    dashboard: LayoutDashboard,
    guide: BookOpen,
    inventory: Package,
    maintenance: Wrench,
    offers: Megaphone,
    orders: ShoppingBag,
    pages: FileText,
    products: Boxes,
    reports: PieChart,
    reviews: Star,
    services: LifeBuoy,
    settings: Settings,
    sim: Smartphone,
    slides: Image,
    support: LifeBuoy,
    loyalty: Gift,
    theme: Paintbrush,
    users: Users,
};

const languages = [
    { code: 'ar', label: 'العربية' },
    { code: 'he', label: 'עברית' },
    { code: 'en', label: 'English' },
];

const topbarButton =
    'relative grid h-10 w-10 place-items-center rounded-full text-ink-soft transition hover:bg-surface hover:text-ink';

/** A link with a query (a filtered list) matches when the page has that filter. */
function matchesQuery(url: string, href: string): boolean {
    const [path, query = ''] = url.split('?');
    const [hrefPath, hrefQuery = ''] = href.split('?');
    const current = new URLSearchParams(query);

    return (
        path === hrefPath &&
        [...new URLSearchParams(hrefQuery)].every(
            ([key, value]) => current.get(key) === value,
        )
    );
}

/**
 * A plain link is active on its page and the pages under it, unless a more
 * specific link (the same page with a filter) matches instead.
 */
function isActive(url: string, href: string, filtered: string[]): boolean {
    if (href.includes('?')) {
        return matchesQuery(url, href);
    }

    const path = url.split('?')[0];

    return (
        (path === href || path.startsWith(`${href}/`)) &&
        !filtered.some(
            (other) => other.startsWith(`${href}?`) && matchesQuery(url, other),
        )
    );
}

function NavLink({
    item,
    url,
    filtered,
    count,
}: {
    item: AdminNavItem;
    url: string;
    filtered: string[];
    count: number;
}) {
    const Icon = icons[item.icon] ?? Settings;
    const active = isActive(url, item.href, filtered);
    const ref = useRef<HTMLAnchorElement>(null);

    useEffect(() => {
        if (active) {
            ref.current?.scrollIntoView({ block: 'nearest' });
        }
    }, [active]);

    return (
        <Link
            ref={ref}
            href={item.href}
            aria-current={active ? 'page' : undefined}
            className={cn(
                'flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold transition lg:py-2',
                active
                    ? 'bg-brand text-on-brand shadow-sm'
                    : 'text-on-topbar/80 hover:bg-on-topbar/10 hover:text-on-topbar',
            )}
        >
            <Icon size={18} aria-hidden="true" className="shrink-0" />
            <span className="truncate">{item.label}</span>
            {count > 0 && (
                <span
                    className={cn(
                        'ms-auto rounded-full px-2 py-0.5 text-xs font-bold',
                        active
                            ? 'bg-on-brand/20 text-on-brand'
                            : 'bg-rose-600 text-white',
                    )}
                >
                    {count > 99 ? '99+' : count}
                </span>
            )}
        </Link>
    );
}

/** The phone's bottom bar: these pages, when the role has them, then the store and the menu. */
const PHONE_TABS = ['dashboard', 'orders', 'products'];

function PhoneTabBar({
    items,
    url,
    alertCounts,
    locale,
    onMenu,
    menuOpen,
}: {
    items: AdminNavItem[];
    url: string;
    alertCounts: Record<string, number>;
    locale: string;
    onMenu: () => void;
    menuOpen: boolean;
}) {
    const t = useAdminText();
    const tab =
        'flex min-w-0 flex-1 flex-col items-center justify-center gap-0.5 px-1 text-[11px] font-semibold transition-colors';
    const tabs = PHONE_TABS.map((icon) =>
        items.find((item) => item.icon === icon && !item.href.includes('?')),
    ).filter((item): item is AdminNavItem => item !== undefined);

    return (
        <nav
            aria-label={t('Store admin')}
            data-admin-tabs
            className="fixed inset-x-0 bottom-0 z-20 border-t border-line bg-header/95 pb-[env(safe-area-inset-bottom)] backdrop-blur lg:hidden"
        >
            <div className="flex h-16">
                {tabs.map((item) => {
                    const Icon = icons[item.icon] ?? Settings;
                    const active = isActive(url, item.href, []);
                    const count = item.alert
                        ? (alertCounts[item.alert] ?? 0)
                        : 0;

                    return (
                        <Link
                            key={item.href}
                            href={item.href}
                            aria-current={active ? 'page' : undefined}
                            className={cn(
                                tab,
                                active ? 'text-brand-text' : 'text-ink-soft',
                            )}
                        >
                            <span className="relative">
                                <Icon size={22} aria-hidden="true" />
                                {count > 0 && (
                                    <span className="absolute -end-2.5 -top-1.5 grid h-4 min-w-4 place-items-center rounded-full bg-rose-600 px-1 text-[10px] font-bold text-white">
                                        {count > 99 ? '99+' : count}
                                    </span>
                                )}
                            </span>
                            <span className="max-w-full truncate">
                                {item.label}
                            </span>
                        </Link>
                    );
                })}
                <a href={`/${locale}`} className={cn(tab, 'text-ink-soft')}>
                    <Store size={22} aria-hidden="true" />
                    <span className="max-w-full truncate">{t('Store')}</span>
                </a>
                <button
                    type="button"
                    onClick={onMenu}
                    aria-expanded={menuOpen}
                    className={cn(
                        tab,
                        menuOpen ? 'text-brand-text' : 'text-ink-soft',
                    )}
                >
                    <Menu size={22} aria-hidden="true" />
                    <span className="max-w-full truncate">{t('Menu')}</span>
                </button>
            </div>
        </nav>
    );
}

export default function StoreAdminLayout({
    children,
    title,
}: {
    children: ReactNode;
    title: string;
}) {
    const { url, props } = usePage();
    const { auth, flash, admin, locale, direction, store } = props;
    const t = useAdminText();

    // A toast stays in view wherever the admin lands, unlike a banner at the
    // top of a long page. Every response brings a new flash object, so the same
    // message twice shows twice; a partial reload keeps the old object and
    // shows nothing again.
    useEffect(() => {
        if (typeof flash?.success === 'string') {
            toast.success(flash.success);
        }

        if (typeof flash?.error === 'string') {
            toast.error(flash.error);
        }
    }, [flash]);
    const [menuOpen, setMenuOpen] = useState(false);

    useEffect(() => {
        if (!menuOpen) {
            return;
        }

        const close = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setMenuOpen(false);
            }
        };
        document.body.style.overflow = 'hidden';
        window.addEventListener('keydown', close);

        return () => {
            document.body.style.overflow = '';
            window.removeEventListener('keydown', close);
        };
    }, [menuOpen]);
    const [filter, setFilter] = useState('');

    const path = url.split('?')[0];
    const alerts = admin?.alerts ?? [];
    const alertCounts = Object.fromEntries(
        alerts.map((alert) => [alert.key, alert.count]),
    );
    const totalAlerts = alerts.reduce((sum, alert) => sum + alert.count, 0);
    const notifications = admin?.notifications ?? { unread: 0, items: [] };
    const term = filter.trim().toLowerCase();
    const groups = (admin?.groups ?? [])
        .map((group) => ({
            ...group,
            items: group.items.filter((item) =>
                item.label.toLowerCase().includes(term),
            ),
        }))
        .filter((group) => group.items.length > 0);
    const filteredLinks = (admin?.groups ?? [])
        .flatMap((group) => group.items)
        .map((item) => item.href)
        .filter((href) => href.includes('?'));

    return (
        <div
            dir={direction}
            // --admin-bottom: where pages pin save and bulk-action bars, above
            // the phone tab bar (PhoneTabBar) and near the edge on computers.
            className="min-h-screen bg-surface text-ink [--admin-bottom:calc(4.75rem+env(safe-area-inset-bottom))] lg:flex lg:[--admin-bottom:max(0.75rem,env(safe-area-inset-bottom))]"
        >
            <Head title={`${title} · ${t('Store admin')}`} />

            {menuOpen && (
                <button
                    type="button"
                    aria-label={t('Close menu')}
                    onClick={() => setMenuOpen(false)}
                    className="fixed inset-0 z-30 bg-black/50 lg:hidden"
                />
            )}

            <aside
                className={cn(
                    'fixed inset-y-0 start-0 z-40 flex w-72 max-w-[85vw] flex-col bg-topbar text-on-topbar transition-transform duration-200 lg:sticky lg:top-0 lg:h-svh lg:shrink-0 lg:translate-x-0 rtl:lg:translate-x-0',
                    menuOpen
                        ? 'translate-x-0'
                        : '-translate-x-full rtl:translate-x-full',
                )}
            >
                <div className="flex items-center justify-between gap-3 px-5 py-5">
                    <Link
                        href="/admin/dashboard"
                        className="flex items-center gap-3 font-black"
                    >
                        <span className="grid h-10 w-10 place-items-center rounded-xl bg-brand text-lg text-on-brand">
                            {store.name.charAt(0).toUpperCase()}
                        </span>
                        <span>
                            {store.name}
                            <span className="block text-xs font-medium text-on-topbar/60">
                                {t('Store admin')}
                            </span>
                        </span>
                    </Link>
                    <button
                        type="button"
                        aria-label={t('Close menu')}
                        onClick={() => setMenuOpen(false)}
                        className="grid h-10 w-10 place-items-center rounded-lg hover:bg-on-topbar/10 lg:hidden"
                    >
                        <X size={18} aria-hidden="true" />
                    </button>
                </div>

                <div className="relative px-4 pb-3">
                    <Search
                        size={15}
                        aria-hidden="true"
                        className="pointer-events-none absolute start-7 top-3 text-on-topbar/50"
                    />
                    <input
                        type="search"
                        value={filter}
                        onChange={(event) => setFilter(event.target.value)}
                        placeholder={t('Find a page')}
                        aria-label={t('Find a page')}
                        className="w-full rounded-lg border border-on-topbar/15 bg-on-topbar/10 py-2 ps-9 pe-3 text-base text-on-topbar outline-none placeholder:text-on-topbar/50 focus:border-brand sm:text-sm"
                    />
                </div>

                <nav
                    aria-label={t('Store admin')}
                    className="flex-1 space-y-4 overflow-y-auto px-3 pb-6"
                >
                    {groups.map((group) => (
                        <div key={group.label}>
                            <p className="px-3 pb-1 text-xs font-bold text-on-topbar/50">
                                {group.label}
                            </p>
                            <div className="grid gap-0.5">
                                {group.items.map((item) => (
                                    <NavLink
                                        key={item.href}
                                        item={item}
                                        url={url}
                                        filtered={filteredLinks}
                                        count={
                                            item.alert
                                                ? (alertCounts[item.alert] ?? 0)
                                                : 0
                                        }
                                    />
                                ))}
                            </div>
                        </div>
                    ))}
                </nav>
            </aside>

            <div className="min-w-0 flex-1">
                <header className="sticky top-0 z-20 border-b border-line bg-header">
                    <div className="flex h-16 items-center gap-1.5 px-3 sm:gap-2 sm:px-6">
                        <button
                            type="button"
                            aria-label={t('Open menu')}
                            onClick={() => setMenuOpen(true)}
                            className={cn(topbarButton, 'lg:hidden')}
                        >
                            <Menu size={20} aria-hidden="true" />
                        </button>

                        <Link
                            href="/admin/dashboard"
                            className="hidden items-center gap-2 rounded-btn px-3 py-2 text-sm font-bold text-ink transition hover:bg-surface sm:inline-flex"
                        >
                            <LayoutDashboard size={16} aria-hidden="true" />
                            {t('Dashboard')}
                        </Link>

                        <a
                            href={`/${locale}`}
                            className="inline-flex min-h-10 items-center gap-2 rounded-btn bg-brand px-3 py-2 text-sm font-bold whitespace-nowrap text-on-brand transition hover:bg-brand-hover min-[380px]:px-4"
                        >
                            <Store size={16} aria-hidden="true" />
                            <span className="max-[379px]:sr-only">
                                {t('Go to store')}
                            </span>
                        </a>

                        <div className="ms-auto flex items-center gap-0.5 sm:gap-1">
                            <ColorModeToggle className={topbarButton} />

                            <DropdownMenu>
                                <DropdownMenuTrigger
                                    aria-label={t('Language')}
                                    className={topbarButton}
                                >
                                    <Globe size={18} aria-hidden="true" />
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    {languages.map((language) => (
                                        <DropdownMenuItem
                                            key={language.code}
                                            asChild
                                        >
                                            <a
                                                href={`${path}?lang=${language.code}`}
                                                aria-current={
                                                    language.code === locale
                                                        ? 'true'
                                                        : undefined
                                                }
                                                className={cn(
                                                    'cursor-pointer',
                                                    language.code === locale &&
                                                        'font-bold text-brand-text',
                                                )}
                                            >
                                                {language.label}
                                            </a>
                                        </DropdownMenuItem>
                                    ))}
                                </DropdownMenuContent>
                            </DropdownMenu>

                            <DropdownMenu>
                                <DropdownMenuTrigger
                                    aria-label={t('Notifications')}
                                    className={topbarButton}
                                >
                                    <Bell size={18} aria-hidden="true" />
                                    {notifications.unread > 0 ? (
                                        <span className="absolute -end-0.5 -top-0.5 grid h-5 min-w-5 place-items-center rounded-full bg-rose-600 px-1 text-[11px] font-bold text-white">
                                            {notifications.unread > 9
                                                ? '9+'
                                                : notifications.unread}
                                        </span>
                                    ) : (
                                        totalAlerts > 0 && (
                                            <span className="absolute end-1 top-1 h-2.5 w-2.5 rounded-full bg-amber-500" />
                                        )
                                    )}
                                </DropdownMenuTrigger>
                                <DropdownMenuContent
                                    align="end"
                                    className="w-80"
                                >
                                    <div className="flex items-center justify-between px-3 pt-2 pb-1">
                                        <p className="text-sm font-extrabold">
                                            {t('Notifications')}
                                        </p>
                                        {notifications.unread > 0 && (
                                            <button
                                                type="button"
                                                className="text-xs font-bold text-brand-text hover:underline"
                                                onClick={() =>
                                                    router.post(
                                                        '/admin/notifications/read-all',
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {t('Mark all as read')}
                                            </button>
                                        )}
                                    </div>
                                    {notifications.items.length ? (
                                        notifications.items.map((entry) => (
                                            <DropdownMenuItem
                                                key={entry.id}
                                                asChild
                                            >
                                                <Link
                                                    href={`/admin/notifications/${entry.id}`}
                                                    className={cn(
                                                        'flex cursor-pointer items-start gap-2',
                                                        !entry.read &&
                                                            'bg-brand/5',
                                                    )}
                                                >
                                                    <span
                                                        className={cn(
                                                            'mt-1.5 h-2 w-2 shrink-0 rounded-full',
                                                            entry.read
                                                                ? 'bg-transparent'
                                                                : 'bg-rose-600',
                                                        )}
                                                    />
                                                    <span className="min-w-0 flex-1">
                                                        <span
                                                            className={cn(
                                                                'block text-sm',
                                                                !entry.read &&
                                                                    'font-bold',
                                                            )}
                                                        >
                                                            {entry.title}
                                                        </span>
                                                        {entry.at && (
                                                            <span className="text-xs text-ink-soft">
                                                                {timeAgo(
                                                                    entry.at,
                                                                    locale,
                                                                )}
                                                            </span>
                                                        )}
                                                    </span>
                                                </Link>
                                            </DropdownMenuItem>
                                        ))
                                    ) : (
                                        <p className="px-3 py-4 text-center text-sm text-ink-soft">
                                            {t('No notifications yet.')}
                                        </p>
                                    )}
                                    <div className="mt-1 border-t border-line pt-1">
                                        <p className="px-3 pt-1 pb-1 text-xs font-bold text-ink-soft">
                                            {t('Needs attention')}
                                        </p>
                                        {alerts.length ? (
                                            alerts.map((alert) => (
                                                <DropdownMenuItem
                                                    key={alert.key}
                                                    asChild
                                                >
                                                    <Link
                                                        href={alert.href}
                                                        className="flex cursor-pointer items-center justify-between gap-3"
                                                    >
                                                        {alert.label}
                                                        <span className="rounded-full bg-rose-100 px-2 py-0.5 text-xs font-bold text-rose-800">
                                                            {alert.count}
                                                        </span>
                                                    </Link>
                                                </DropdownMenuItem>
                                            ))
                                        ) : (
                                            <p className="px-3 py-3 text-center text-sm text-ink-soft">
                                                {t(
                                                    'Nothing needs your attention.',
                                                )}
                                            </p>
                                        )}
                                    </div>
                                </DropdownMenuContent>
                            </DropdownMenu>

                            <DropdownMenu>
                                <DropdownMenuTrigger className="flex items-center gap-2 rounded-full py-1 ps-1 pe-2 transition hover:bg-surface">
                                    <span className="grid h-8 w-8 place-items-center rounded-full bg-brand/15 text-sm font-black text-brand-text">
                                        {auth.user?.name
                                            .charAt(0)
                                            .toUpperCase()}
                                    </span>
                                    <span className="hidden max-w-32 truncate text-sm font-bold sm:block">
                                        {auth.user?.name}
                                    </span>
                                    <ChevronDown
                                        size={14}
                                        aria-hidden="true"
                                        className="hidden text-ink-soft sm:block"
                                    />
                                </DropdownMenuTrigger>
                                <DropdownMenuContent
                                    align="end"
                                    className="w-56"
                                >
                                    <div className="px-2 py-2">
                                        <p className="truncate text-sm font-bold">
                                            {auth.user?.name}
                                        </p>
                                        <p className="text-xs text-ink-soft">
                                            {admin?.role}
                                        </p>
                                    </div>
                                    <DropdownMenuItem asChild>
                                        <a
                                            href="/settings/security"
                                            className="flex cursor-pointer items-center gap-2"
                                        >
                                            <ShieldCheck
                                                size={16}
                                                aria-hidden="true"
                                            />
                                            {t('Account security')}
                                        </a>
                                    </DropdownMenuItem>
                                    <DropdownMenuItem asChild>
                                        <Link
                                            href="/logout"
                                            method="post"
                                            as="button"
                                            className="flex w-full cursor-pointer items-center gap-2"
                                        >
                                            <LogOut
                                                size={16}
                                                aria-hidden="true"
                                                className="rtl:rotate-180"
                                            />
                                            {t('Log out')}
                                        </Link>
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    </div>
                </header>

                {/* Bottom padding on phones keeps the last buttons above the tab bar. */}
                <main className="mx-auto max-w-6xl px-3 pt-5 pb-[calc(6rem+env(safe-area-inset-bottom))] sm:px-6 sm:pt-7 lg:px-8 lg:pb-7">
                    {children}
                </main>
            </div>

            <PhoneTabBar
                items={(admin?.groups ?? []).flatMap((group) => group.items)}
                url={url}
                alertCounts={alertCounts}
                locale={locale}
                onMenu={() => setMenuOpen(true)}
                menuOpen={menuOpen}
            />
        </div>
    );
}
