import { router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import {
    PageHeader,
    panel,
    primaryButton,
    secondaryButton,
} from '@/components/admin/admin-ui';
import { useAdminText } from '@/hooks/use-admin-text';
import StoreAdminLayout from '@/layouts/store-admin-layout';
import { formatMoney } from '@/lib/store';
import { cn } from '@/lib/utils';

type Day = { date: string; total: number; orders: number };
type Ranked = { name: string; quantity: number; revenue: number };
type Group = { key: string; orders: number; amount: number };

type Report = {
    kpis: {
        revenue: number;
        orders: number;
        average: number;
        items: number;
        discounts: number;
        shipping: number;
        cancelled: number;
        new_customers: number;
    };
    days: Day[];
    top_products: (Ranked & { id: number | null })[];
    top_categories: Ranked[];
    coupons: Group[];
    payment_methods: Group[];
    delivery_methods: Group[];
    statuses: { status: string; order_count: number; revenue: number }[];
    points: { outstanding: number; earned: number; redeemed: number };
    catalog: {
        total: number;
        active: number;
        out_of_stock: number;
        estimated_prices: number;
    };
};

const money = (amount: number) => formatMoney(amount, 'ILS', 'en');

/** Long ranges are shown by week, so each bar stays wide enough to read and hover. */
function buckets(days: Day[]): Day[] {
    if (days.length <= 92) {
        return days;
    }

    const weeks: Day[] = [];

    days.forEach((day, index) => {
        if (index % 7 === 0) {
            weeks.push({ ...day });
        } else {
            const week = weeks[weeks.length - 1];
            week.total += day.total;
            week.orders += day.orders;
        }
    });

    return weeks;
}

function SalesChart({ days, weekly }: { days: Day[]; weekly: boolean }) {
    const t = useAdminText();
    const [active, setActive] = useState<number | null>(null);
    const max = Math.max(...days.map((day) => day.total), 1);
    const shown = active === null ? null : days[active];
    const label = (day: Day) =>
        weekly ? t('Week of :date', { date: day.date }) : day.date;

    return (
        <div>
            <div className="mb-2 h-10 text-sm" aria-live="polite">
                {shown ? (
                    <>
                        <p className="font-bold text-ink">{label(shown)}</p>
                        <p className="text-ink-soft">
                            <span dir="ltr">{money(shown.total)}</span> ·{' '}
                            {t(':count orders', { count: shown.orders })}
                        </p>
                    </>
                ) : (
                    <p className="text-ink-soft">
                        {t('Point at a bar to see its sales.')}
                    </p>
                )}
            </div>
            <div
                className="relative h-48 border-b border-line"
                dir="ltr"
                onMouseLeave={() => setActive(null)}
                aria-hidden="true"
            >
                <div className="absolute inset-x-0 top-0 border-t border-dashed border-line/70" />
                <span className="absolute -top-2.5 right-0 bg-panel ps-1 text-[11px] text-ink-soft">
                    {money(max)}
                </span>
                <div className="flex h-full items-end gap-[2px]">
                    {days.map((day, index) => (
                        <div
                            key={day.date}
                            className="flex h-full min-w-0 flex-1 cursor-default items-end"
                            onMouseEnter={() => setActive(index)}
                        >
                            <div
                                className={cn(
                                    'w-full rounded-t-[4px] bg-brand transition-opacity',
                                    active !== null &&
                                        active !== index &&
                                        'opacity-40',
                                )}
                                style={{
                                    height: `${(day.total / max) * 100}%`,
                                    minHeight: day.total > 0 ? 2 : 0,
                                }}
                            />
                        </div>
                    ))}
                </div>
            </div>
            <div
                className="mt-1 flex justify-between text-[11px] text-ink-soft"
                dir="ltr"
                aria-hidden="true"
            >
                <span>{days[0]?.date}</span>
                <span>{days[days.length - 1]?.date}</span>
            </div>
            <table className="sr-only">
                <caption>{t('Sales per day')}</caption>
                <thead>
                    <tr>
                        <th>{t('Date')}</th>
                        <th>{t('Sales')}</th>
                        <th>{t('Orders')}</th>
                    </tr>
                </thead>
                <tbody>
                    {days.map((day) => (
                        <tr key={day.date}>
                            <td>{label(day)}</td>
                            <td>{money(day.total)}</td>
                            <td>{day.orders}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function RankedList({
    title,
    rows,
    empty,
}: {
    title: string;
    rows: Ranked[];
    empty: string;
}) {
    const t = useAdminText();
    const max = Math.max(...rows.map((row) => row.revenue), 1);

    return (
        <section className={`${panel} p-5`}>
            <h2 className="text-lg font-extrabold">{title}</h2>
            {rows.length ? (
                <ol className="mt-4 space-y-3">
                    {rows.map((row, index) => (
                        <li key={`${row.name}-${index}`} className="text-sm">
                            <div className="flex justify-between gap-3">
                                <span className="min-w-0 truncate font-semibold text-ink">
                                    {index + 1}. {row.name}
                                </span>
                                <span
                                    className="shrink-0 text-ink-soft"
                                    dir="ltr"
                                >
                                    {money(row.revenue)} ·{' '}
                                    {t(':count sold', { count: row.quantity })}
                                </span>
                            </div>
                            <div className="mt-1 h-1.5 rounded-full bg-surface">
                                <div
                                    className="h-full rounded-full bg-brand"
                                    style={{
                                        width: `${(row.revenue / max) * 100}%`,
                                    }}
                                />
                            </div>
                        </li>
                    ))}
                </ol>
            ) : (
                <p className="mt-4 text-sm text-ink-soft">{empty}</p>
            )}
        </section>
    );
}

function GroupTable({
    title,
    rows,
    amountLabel,
    name,
}: {
    title: string;
    rows: Group[];
    amountLabel: string;
    name: (key: string) => string;
}) {
    const t = useAdminText();

    return (
        <section className={`${panel} overflow-hidden`}>
            <h2 className="border-b border-line px-5 py-4 text-lg font-extrabold">
                {title}
            </h2>
            {rows.length ? (
                <table className="w-full text-sm">
                    <thead className="bg-surface text-xs text-ink-soft">
                        <tr>
                            <th className="px-5 py-2.5 text-start">
                                {t('Name')}
                            </th>
                            <th className="px-5 py-2.5 text-start">
                                {t('Orders')}
                            </th>
                            <th className="px-5 py-2.5 text-end">
                                {amountLabel}
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                        {rows.map((row) => (
                            <tr key={row.key}>
                                <td className="px-5 py-2.5 font-semibold">
                                    {name(row.key)}
                                </td>
                                <td className="px-5 py-2.5">{row.orders}</td>
                                <td className="px-5 py-2.5 text-end" dir="ltr">
                                    {money(row.amount)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            ) : (
                <p className="px-5 py-6 text-sm text-ink-soft">
                    {t('Nothing in this period.')}
                </p>
            )}
        </section>
    );
}

export default function StoreReports({
    range,
    report,
}: {
    range: { from: string; to: string };
    report: Report;
}) {
    const t = useAdminText();
    const [from, setFrom] = useState(range.from);
    const [to, setTo] = useState(range.to);
    const days = buckets(report.days);
    const query = new URLSearchParams(range).toString();

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        router.get('/admin/reports', { from, to }, { preserveScroll: true });
    }

    const tiles: [string, string][] = [
        ['Sales', money(report.kpis.revenue)],
        ['Orders', String(report.kpis.orders)],
        ['Average order', money(report.kpis.average)],
        ['Items sold', String(report.kpis.items)],
        ['Discounts given', money(report.kpis.discounts)],
        ['Delivery fees', money(report.kpis.shipping)],
        ['Cancelled orders', String(report.kpis.cancelled)],
        ['New customers', String(report.kpis.new_customers)],
    ];

    return (
        <StoreAdminLayout title={t('Reports')}>
            <PageHeader
                eyebrow={t('Analytics')}
                title={t('Reports')}
                description={t('Sales exclude cancelled orders.')}
            >
                <form
                    onSubmit={submit}
                    className="flex flex-wrap items-center gap-2"
                >
                    <input
                        type="date"
                        value={from}
                        max={to}
                        onChange={(event) => setFrom(event.target.value)}
                        aria-label={t('From')}
                        className="admin-input w-auto"
                    />
                    <input
                        type="date"
                        value={to}
                        min={from}
                        onChange={(event) => setTo(event.target.value)}
                        aria-label={t('To')}
                        className="admin-input w-auto"
                    />
                    <button type="submit" className={primaryButton}>
                        {t('Show')}
                    </button>
                    <a
                        href={`/admin/reports/orders.csv?${query}`}
                        className={secondaryButton}
                    >
                        <Download size={16} aria-hidden="true" />
                        {t('Export orders')}
                    </a>
                </form>
            </PageHeader>

            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {tiles.map(([label, value]) => (
                    <div key={label} className={`${panel} p-4`}>
                        <p className="text-sm font-semibold text-ink-soft">
                            {t(label)}
                        </p>
                        <p
                            className="mt-1 text-2xl font-black text-ink"
                            dir="ltr"
                        >
                            {value}
                        </p>
                    </div>
                ))}
            </div>

            <section className={`${panel} mt-5 p-5`}>
                <h2 className="mb-3 text-lg font-extrabold">
                    {days.length === report.days.length
                        ? t('Sales per day')
                        : t('Sales per week')}
                </h2>
                <SalesChart
                    days={days}
                    weekly={days.length !== report.days.length}
                />
            </section>

            <div className="mt-5 grid gap-5 lg:grid-cols-2">
                <RankedList
                    title={t('Best-selling products')}
                    rows={report.top_products}
                    empty={t('Nothing in this period.')}
                />
                <RankedList
                    title={t('Best-selling categories')}
                    rows={report.top_categories}
                    empty={t('Nothing in this period.')}
                />
                <GroupTable
                    title={t('Coupons used')}
                    rows={report.coupons}
                    amountLabel={t('Discount')}
                    name={(key) => key}
                />
                <GroupTable
                    title={t('Payment methods')}
                    rows={report.payment_methods}
                    amountLabel={t('Sales')}
                    name={(key) =>
                        key === 'whatsapp' ? 'WhatsApp' : t('Online payment')
                    }
                />
                <GroupTable
                    title={t('Delivery')}
                    rows={report.delivery_methods}
                    amountLabel={t('Delivery fees')}
                    name={(key) =>
                        key === 'pickup'
                            ? t('Pickup from branch')
                            : t('Delivery')
                    }
                />
                <section className={`${panel} overflow-hidden`}>
                    <h2 className="border-b border-line px-5 py-4 text-lg font-extrabold">
                        {t('Orders by status')}
                    </h2>
                    <table className="w-full text-sm">
                        <tbody className="divide-y divide-line">
                            {report.statuses.map((row) => (
                                <tr key={row.status}>
                                    <td className="px-5 py-2.5 font-semibold">
                                        {row.status}
                                    </td>
                                    <td className="px-5 py-2.5">
                                        {row.order_count}
                                    </td>
                                    <td
                                        className="px-5 py-2.5 text-end"
                                        dir="ltr"
                                    >
                                        {money(row.revenue)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>
            </div>

            <div className="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {(
                    [
                        ['Points customers hold', report.points.outstanding],
                        ['Points earned in period', report.points.earned],
                        ['Points spent in period', report.points.redeemed],
                        [
                            'Products with estimated prices',
                            report.catalog.estimated_prices,
                        ],
                        ['Products', report.catalog.total],
                        ['Active products', report.catalog.active],
                        ['Out of stock', report.catalog.out_of_stock],
                    ] as [string, number][]
                ).map(([label, value]) => (
                    <div key={label} className={`${panel} p-4`}>
                        <p className="text-sm font-semibold text-ink-soft">
                            {t(label)}
                        </p>
                        <p className="mt-1 text-2xl font-black text-ink">
                            {value.toLocaleString('en')}
                        </p>
                    </div>
                ))}
            </div>
        </StoreAdminLayout>
    );
}
