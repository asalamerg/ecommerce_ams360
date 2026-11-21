import { Link, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Boxes,
    ClipboardList,
    MessageSquare,
    PackageCheck,
    Paintbrush,
    Plus,
    ShoppingBag,
    Star,
    Tags,
    TrendingUp,
} from 'lucide-react';
import {
    PageHeader,
    panel,
    secondaryButton,
} from '@/components/admin/admin-ui';
import { useAdminText, useDateFormatter } from '@/hooks/use-admin-text';
import StoreAdminLayout from '@/layouts/store-admin-layout';
import { formatMoney } from '@/lib/store';

type Permissions = {
    catalog: boolean;
    inventory: boolean;
    orders: boolean;
    support: boolean;
    reports: boolean;
};

type Day = { date: string; total: number; orders: number };

type Props = {
    can: Permissions;
    metrics: {
        products: number;
        active_products: number;
        categories: number;
        orders: number;
        pending_orders: number;
        unread_messages: number;
        unapproved_reviews: number;
        low_stock: number;
    };
    sales: {
        today: string;
        today_orders: number;
        period: string;
        period_orders: number;
        days: Day[];
    } | null;
    recentOrders: {
        id: number;
        customer: string;
        total: string;
        status: string;
        created_at: string | null;
    }[];
    lowStock: { id: number; name: string; stock: number }[];
    systemWarnings: { key: string; message: string }[];
};

const cards: {
    key: keyof Props['metrics'];
    label: string;
    icon: typeof Boxes;
    href: string;
    needs: keyof Permissions;
}[] = [
    {
        key: 'pending_orders',
        label: 'Pending orders',
        icon: ClipboardList,
        href: '/admin/orders',
        needs: 'orders',
    },
    {
        key: 'orders',
        label: 'All orders',
        icon: ShoppingBag,
        href: '/admin/orders',
        needs: 'orders',
    },
    {
        key: 'products',
        label: 'Products',
        icon: Boxes,
        href: '/admin/products',
        needs: 'catalog',
    },
    {
        key: 'categories',
        label: 'Categories',
        icon: Tags,
        href: '/admin/categories',
        needs: 'catalog',
    },
    {
        key: 'low_stock',
        label: 'Low-stock products',
        icon: PackageCheck,
        href: '/admin/inventory',
        needs: 'inventory',
    },
    {
        key: 'unread_messages',
        label: 'Unread messages',
        icon: MessageSquare,
        href: '/admin/modules/contact',
        needs: 'support',
    },
    {
        key: 'unapproved_reviews',
        label: 'Reviews to moderate',
        icon: Star,
        href: '/admin/modules/reviews',
        needs: 'support',
    },
];

function SalesChart({ days, locale }: { days: Day[]; locale: string }) {
    const t = useAdminText();
    const highest = Math.max(...days.map((day) => day.total), 1);
    const dateLabel = new Intl.DateTimeFormat(`${locale}-u-nu-latn`, {
        day: 'numeric',
        month: 'short',
    });

    return (
        <figure>
            <div
                aria-hidden="true"
                className="flex h-44 items-end gap-1 border-b border-line sm:gap-2"
            >
                {days.map((day) => (
                    <div
                        key={day.date}
                        className="group relative flex h-full flex-1 items-end justify-center"
                    >
                        <div
                            className="w-full max-w-7 rounded-t bg-brand transition group-hover:bg-brand-hover"
                            style={{
                                height: `${Math.max((day.total / highest) * 100, day.total > 0 ? 3 : 0)}%`,
                            }}
                        />
                        <div className="pointer-events-none absolute bottom-full z-10 mb-1 hidden rounded-lg bg-ink px-2.5 py-1.5 text-xs whitespace-nowrap text-panel shadow-lg group-hover:block">
                            <p className="font-bold">
                                {formatMoney(day.total, 'ILS', 'en')}
                            </p>
                            <p>
                                {dateLabel.format(new Date(day.date))} ·{' '}
                                {t(':count orders', { count: day.orders })}
                            </p>
                        </div>
                    </div>
                ))}
            </div>
            <div
                aria-hidden="true"
                className="mt-2 flex justify-between text-xs text-ink-soft"
            >
                <span>{dateLabel.format(new Date(days[0].date))}</span>
                <span>
                    {dateLabel.format(new Date(days[days.length - 1].date))}
                </span>
            </div>
            <table className="sr-only">
                <caption>{t('Sales in the last 14 days')}</caption>
                <tbody>
                    {days.map((day) => (
                        <tr key={day.date}>
                            <th scope="row">
                                {dateLabel.format(new Date(day.date))}
                            </th>
                            <td>{formatMoney(day.total, 'ILS', 'en')}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </figure>
    );
}

export default function StoreAdminDashboard({
    can,
    metrics,
    sales,
    recentOrders,
    lowStock,
    systemWarnings,
}: Props) {
    const { auth, locale, name: appName } = usePage().props;
    const t = useAdminText();
    const formatDate = useDateFormatter();

    return (
        <StoreAdminLayout title={t('Dashboard')}>
            <PageHeader
                eyebrow={appName}
                title={t('Welcome, :name', { name: auth.user?.name ?? '' })}
                description={t('Store operations at a glance')}
            >
                <div className="flex flex-wrap gap-2">
                    {can.catalog && (
                        <Link
                            href="/admin/products/create"
                            className={secondaryButton}
                        >
                            <Plus size={16} aria-hidden="true" />
                            {t('Add product')}
                        </Link>
                    )}
                    {can.reports && (
                        <Link href="/admin/theme" className={secondaryButton}>
                            <Paintbrush size={16} aria-hidden="true" />
                            {t('Theme')}
                        </Link>
                    )}
                    {can.reports && (
                        <Link href="/admin/reports" className={secondaryButton}>
                            <TrendingUp size={16} aria-hidden="true" />
                            {t('Reports')}
                        </Link>
                    )}
                </div>
            </PageHeader>

            {systemWarnings.length > 0 && (
                <section
                    role="alert"
                    className="mb-5 rounded-2xl border border-amber-300 bg-amber-50 p-4 text-amber-950 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100"
                >
                    <p className="mb-2 flex items-center gap-2 font-extrabold">
                        <AlertTriangle size={18} aria-hidden="true" />
                        {t('System check')}
                    </p>
                    <ul className="list-disc space-y-1 ps-6 text-sm">
                        {systemWarnings.map((warning) => (
                            <li key={warning.key}>{warning.message}</li>
                        ))}
                    </ul>
                </section>
            )}

            {sales && (
                <section className={`${panel} mb-5 p-5 sm:p-6`}>
                    <div className="mb-5 flex flex-wrap gap-x-10 gap-y-3">
                        <div>
                            <p className="text-sm font-semibold text-ink-soft">
                                {t('Sales today')}
                            </p>
                            <p className="mt-1 text-3xl font-black" dir="ltr">
                                {formatMoney(sales.today, 'ILS', 'en')}
                            </p>
                            <p className="text-xs text-ink-soft">
                                {t(':count orders', {
                                    count: sales.today_orders,
                                })}
                            </p>
                        </div>
                        <div>
                            <p className="text-sm font-semibold text-ink-soft">
                                {t('Sales in the last 14 days')}
                            </p>
                            <p className="mt-1 text-3xl font-black" dir="ltr">
                                {formatMoney(sales.period, 'ILS', 'en')}
                            </p>
                            <p className="text-xs text-ink-soft">
                                {t(':count orders', {
                                    count: sales.period_orders,
                                })}
                            </p>
                        </div>
                    </div>
                    {sales.period_orders > 0 ? (
                        <SalesChart days={sales.days} locale={locale} />
                    ) : (
                        <p className="rounded-xl bg-surface px-4 py-6 text-center text-sm text-ink-soft">
                            {t(
                                'The sales chart will appear after the first order.',
                            )}
                        </p>
                    )}
                </section>
            )}

            <div className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
                {cards
                    .filter((card) => can[card.needs])
                    .map(({ key, label, icon: Icon, href }) => (
                        <Link
                            key={key}
                            href={href}
                            className={`${panel} group p-4 transition hover:-translate-y-0.5 hover:border-brand hover:shadow-md motion-reduce:transform-none sm:p-5`}
                        >
                            <div className="flex items-center justify-between">
                                <span className="grid h-10 w-10 place-items-center rounded-xl bg-brand/10 text-brand-text">
                                    <Icon size={19} aria-hidden="true" />
                                </span>
                                <span className="text-2xl font-black">
                                    {metrics[key]}
                                </span>
                            </div>
                            <p className="mt-3 text-sm font-bold text-ink-soft group-hover:text-brand-text">
                                {t(label)}
                            </p>
                        </Link>
                    ))}
            </div>

            <div className="mt-5 grid gap-5 lg:grid-cols-2">
                {can.orders && (
                    <section className={panel}>
                        <div className="flex items-center justify-between border-b border-line px-5 py-4">
                            <h2 className="font-extrabold">
                                {t('Latest orders')}
                            </h2>
                            <Link
                                href="/admin/orders"
                                className="text-sm font-bold text-brand-text"
                            >
                                {t('View all')}
                            </Link>
                        </div>
                        {recentOrders.length ? (
                            <ul className="divide-y divide-line">
                                {recentOrders.map((order) => (
                                    <li key={order.id}>
                                        <Link
                                            href={`/admin/orders/${order.id}`}
                                            className="flex items-center justify-between gap-3 px-5 py-3 transition hover:bg-surface"
                                        >
                                            <span className="min-w-0">
                                                <span className="block truncate text-sm font-bold">
                                                    {order.customer}
                                                </span>
                                                <span className="text-xs text-ink-soft">
                                                    {order.status}
                                                    {order.created_at &&
                                                        ` · ${formatDate(order.created_at)}`}
                                                </span>
                                            </span>
                                            <span
                                                className="text-sm font-bold"
                                                dir="ltr"
                                            >
                                                {formatMoney(
                                                    order.total,
                                                    'ILS',
                                                    'en',
                                                )}
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="px-5 py-10 text-center text-sm text-ink-soft">
                                {t('No orders yet.')}
                            </p>
                        )}
                    </section>
                )}

                {can.inventory && (
                    <section className={panel}>
                        <div className="flex items-center justify-between border-b border-line px-5 py-4">
                            <h2 className="flex items-center gap-2 font-extrabold">
                                <AlertTriangle
                                    size={17}
                                    aria-hidden="true"
                                    className="text-urgent"
                                />
                                {t('Low stock')}
                            </h2>
                            <Link
                                href="/admin/inventory"
                                className="text-sm font-bold text-brand-text"
                            >
                                {t('Manage inventory')}
                            </Link>
                        </div>
                        {lowStock.length ? (
                            <ul className="divide-y divide-line">
                                {lowStock.map((product) => (
                                    <li
                                        key={product.id}
                                        className="flex items-center justify-between gap-3 px-5 py-3"
                                    >
                                        <span className="truncate text-sm font-semibold">
                                            {product.name}
                                        </span>
                                        <span className="shrink-0 rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-bold text-rose-800">
                                            {t(':count left', {
                                                count: product.stock,
                                            })}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="px-5 py-10 text-center text-sm text-ink-soft">
                                {t('All products are well stocked.')}
                            </p>
                        )}
                    </section>
                )}
            </div>
        </StoreAdminLayout>
    );
}
