import { Link, usePage } from '@inertiajs/react';
import {
    Home,
    LayoutDashboard,
    LayoutGrid,
    ShoppingCart,
    UserRound,
} from 'lucide-react';
import { useTranslations } from '@/hooks/use-translations';
import { cn } from '@/lib/utils';

/**
 * The phone's main navigation, kept within thumb reach as in the old store. The
 * safe-area padding stops it colliding with the iPhone home indicator. Staff
 * get a fifth tab back to the admin panel, always in reach on a phone.
 */
export default function MobileTabBar() {
    const { url, props } = usePage();
    const { auth, cart, locale } = props;
    const t = useTranslations();
    const path = url.split('?')[0];
    const count = cart?.count ?? 0;

    const tabs = [
        {
            href: `/${locale}`,
            label: t('store.home'),
            icon: Home,
            active: path === `/${locale}`,
        },
        {
            href: `/${locale}/categories`,
            label: t('store.categories_tab'),
            icon: LayoutGrid,
            active:
                path.startsWith(`/${locale}/categories`) ||
                path.startsWith(`/${locale}/products`),
        },
        {
            href: `/${locale}/cart`,
            label: t('store.cart'),
            icon: ShoppingCart,
            active: path === `/${locale}/cart`,
            cart: true,
        },
        {
            href: auth.user ? `/${locale}/account` : '/login',
            label: t('nav.account'),
            icon: UserRound,
            active: path === `/${locale}/account`,
        },
        ...(auth.user?.is_staff
            ? [
                  {
                      href: '/dashboard',
                      label: t('nav.admin_short'),
                      icon: LayoutDashboard,
                      active: false,
                      // The admin is another layout: a full page load.
                      external: true,
                  },
              ]
            : []),
    ];

    return (
        <nav
            aria-label={t('store.home')}
            className="fixed inset-x-0 bottom-0 z-40 border-t border-line bg-header/95 pb-[env(safe-area-inset-bottom)] backdrop-blur md:hidden"
        >
            <div className="flex h-16">
                {tabs.map(
                    ({
                        href,
                        label,
                        icon: Icon,
                        active,
                        cart: isCart,
                        external,
                    }) => {
                        const className = cn(
                            'flex flex-1 flex-col items-center justify-center gap-0.5 text-xs font-semibold transition-colors',
                            active ? 'text-brand-text' : 'text-ink-soft',
                        );
                        const content = (
                            <>
                                <span
                                    className="relative"
                                    {...(isCart
                                        ? { 'data-cart-target': true }
                                        : {})}
                                >
                                    <Icon size={22} aria-hidden="true" />
                                    {isCart && count > 0 && (
                                        <span
                                            key={count}
                                            className="badge-pop absolute -end-2 -top-1.5 grid h-4 min-w-4 place-items-center rounded-full bg-brand px-1 text-[10px] font-bold text-on-brand"
                                        >
                                            {count > 9 ? '9+' : count}
                                        </span>
                                    )}
                                </span>
                                {label}
                            </>
                        );

                        return external ? (
                            <a key={href} href={href} className={className}>
                                {content}
                            </a>
                        ) : (
                            <Link
                                key={href}
                                href={href}
                                aria-current={active ? 'page' : undefined}
                                className={className}
                            >
                                {content}
                            </Link>
                        );
                    },
                )}
            </div>
        </nav>
    );
}
