import { Link, router } from '@inertiajs/react';
import { ShoppingBag } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import {
    EmptyState,
    PageHeader,
    Pagination,
    panel,
    primaryButton,
    secondaryButton,
} from '@/components/admin/admin-ui';
import { Badge } from '@/components/admin/order-badge';
import type { OrderOptions } from '@/components/admin/order-badge';
import { useAdminText, useDateFormatter } from '@/hooks/use-admin-text';
import StoreAdminLayout from '@/layouts/store-admin-layout';
import { formatMoney } from '@/lib/store';
import { cn } from '@/lib/utils';
import type { PageLink } from '@/types';

type Row = {
    id: number;
    reference: string;
    customer: string;
    phone: string;
    total: string;
    items: number;
    status: string;
    payment_status: string;
    payment_method: string | null;
    delivery_method: string;
    created_at: string | null;
};

type Filters = {
    status: string;
    payment: string;
    from: string;
    to: string;
    q: string;
};

export default function AdminOrders({
    orders,
    filters,
    counts,
    options,
}: {
    orders: { data: Row[]; links: PageLink[]; total: number };
    filters: Filters;
    counts: Record<string, number>;
    options: OrderOptions;
}) {
    const t = useAdminText();
    const formatDate = useDateFormatter();
    const [values, setValues] = useState<Filters>(filters);
    const [selected, setSelected] = useState<number[]>([]);
    const [bulkStatus, setBulkStatus] = useState('processing');
    const [notify, setNotify] = useState(true);
    const all = Object.values(counts).reduce((sum, count) => sum + count, 0);

    function apply(next: Partial<Filters>) {
        const query = Object.fromEntries(
            Object.entries({ ...values, ...next }).filter(([, v]) => v !== ''),
        );
        router.get('/admin/orders', query, { preserveState: true });
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        apply({});
    }

    function toggle(id: number) {
        setSelected((current) =>
            current.includes(id)
                ? current.filter((value) => value !== id)
                : [...current, id],
        );
    }

    function bulk() {
        router.post(
            '/admin/orders/bulk-status',
            { ids: selected, status: bulkStatus, notify_customer: notify },
            { preserveScroll: true, onSuccess: () => setSelected([]) },
        );
    }

    const tabs: [string, string, number][] = [
        ['', t('All'), all],
        ...Object.entries(options.statuses).map(
            ([key, label]): [string, string, number] => [
                key,
                label,
                counts[key] ?? 0,
            ],
        ),
    ];
    const allChecked =
        orders.data.length > 0 &&
        orders.data.every((order) => selected.includes(order.id));

    return (
        <StoreAdminLayout title={t('Orders')}>
            <PageHeader
                eyebrow={t('Sales')}
                title={t('Orders')}
                description={t(
                    'Confirm, prepare and deliver orders. Every change is kept in the order history.',
                )}
            />

            <div className="mb-4 flex flex-wrap gap-2">
                {tabs.map(([key, label, count]) => (
                    <button
                        key={key || 'all'}
                        type="button"
                        onClick={() => {
                            setValues({ ...values, status: key });
                            apply({ status: key });
                        }}
                        className={cn(
                            'rounded-full border px-3.5 py-1.5 text-sm font-bold transition',
                            filters.status === key
                                ? 'border-brand bg-brand text-on-brand'
                                : 'border-line bg-panel hover:border-brand',
                        )}
                    >
                        {label}
                        <span className="ms-1.5 opacity-75">{count}</span>
                    </button>
                ))}
            </div>

            <form
                onSubmit={submit}
                className={`${panel} mb-4 grid grid-cols-[minmax(0,1fr)] gap-3 p-4 sm:grid-cols-2 lg:grid-cols-5`}
            >
                <input
                    type="search"
                    value={values.q}
                    onChange={(event) =>
                        setValues({ ...values, q: event.target.value })
                    }
                    placeholder={t('Order number, name, phone or e-mail')}
                    className="admin-input lg:col-span-2"
                />
                <select
                    value={values.payment}
                    onChange={(event) =>
                        setValues({ ...values, payment: event.target.value })
                    }
                    className="admin-input"
                    aria-label={t('Payment status')}
                >
                    <option value="">{t('Any payment status')}</option>
                    {Object.entries(options.payment_statuses).map(
                        ([key, label]) => (
                            <option key={key} value={key}>
                                {label}
                            </option>
                        ),
                    )}
                </select>
                <div className="flex flex-wrap gap-2 sm:flex-nowrap lg:col-span-2">
                    <input
                        type="date"
                        value={values.from}
                        onChange={(event) =>
                            setValues({ ...values, from: event.target.value })
                        }
                        aria-label={t('From')}
                        className="admin-input min-w-[9rem] flex-1 sm:min-w-0"
                    />
                    <input
                        type="date"
                        value={values.to}
                        onChange={(event) =>
                            setValues({ ...values, to: event.target.value })
                        }
                        aria-label={t('To')}
                        className="admin-input min-w-[9rem] flex-1 sm:min-w-0"
                    />
                    <button
                        type="submit"
                        className={`${primaryButton} max-sm:w-full`}
                    >
                        {t('Filter')}
                    </button>
                </div>
            </form>

            {selected.length > 0 && (
                <div
                    className={`${panel} mb-4 flex flex-wrap items-center gap-3 p-3 max-sm:fixed max-sm:inset-x-3 max-sm:bottom-(--admin-bottom) max-sm:z-30 max-sm:mb-0 max-sm:flex-nowrap max-sm:overflow-x-auto max-sm:shadow-xl sm:sticky sm:top-16 sm:z-10 [&>*]:shrink-0`}
                >
                    <span className="text-sm font-bold">
                        {t(':count selected', { count: selected.length })}
                    </span>
                    <select
                        value={bulkStatus}
                        onChange={(event) => setBulkStatus(event.target.value)}
                        className="admin-input w-auto"
                        aria-label={t('Order status')}
                    >
                        {Object.entries(options.statuses).map(
                            ([key, label]) => (
                                <option key={key} value={key}>
                                    {label}
                                </option>
                            ),
                        )}
                    </select>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={notify}
                            onChange={(event) =>
                                setNotify(event.target.checked)
                            }
                        />
                        {t('E-mail customers')}
                    </label>
                    <button
                        type="button"
                        onClick={bulk}
                        className={primaryButton}
                    >
                        {t('Apply to selected')}
                    </button>
                    <button
                        type="button"
                        onClick={() => setSelected([])}
                        className={secondaryButton}
                    >
                        {t('Cancel')}
                    </button>
                </div>
            )}

            <section className={`${panel} overflow-hidden`}>
                {orders.data.length ? (
                    <div className="overflow-x-auto">
                        <table className="admin-table w-full min-w-[820px] text-sm">
                            <thead className="bg-surface text-xs text-ink-soft">
                                <tr>
                                    <th className="w-10 px-4 py-3">
                                        <input
                                            type="checkbox"
                                            aria-label={t('Select all')}
                                            checked={allChecked}
                                            onChange={() =>
                                                setSelected(
                                                    allChecked
                                                        ? []
                                                        : orders.data.map(
                                                              (order) =>
                                                                  order.id,
                                                          ),
                                                )
                                            }
                                        />
                                    </th>
                                    <th className="px-4 py-3 text-start">
                                        {t('Order number')}
                                    </th>
                                    <th className="px-4 py-3 text-start">
                                        {t('Customer')}
                                    </th>
                                    <th className="px-4 py-3 text-start">
                                        {t('Total')}
                                    </th>
                                    <th className="px-4 py-3 text-start">
                                        {t('Order status')}
                                    </th>
                                    <th className="px-4 py-3 text-start">
                                        {t('Payment')}
                                    </th>
                                    <th className="px-4 py-3 text-start">
                                        {t('Date')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {orders.data.map((order) => (
                                    <tr
                                        key={order.id}
                                        className={cn(
                                            'transition hover:bg-surface/60',
                                            selected.includes(order.id) &&
                                                'bg-brand/5',
                                        )}
                                    >
                                        <td
                                            data-cell="select"
                                            className="px-4 py-3"
                                        >
                                            <input
                                                type="checkbox"
                                                aria-label={order.reference}
                                                checked={selected.includes(
                                                    order.id,
                                                )}
                                                onChange={() =>
                                                    toggle(order.id)
                                                }
                                            />
                                        </td>
                                        <td
                                            data-cell="primary"
                                            className="px-4 py-3"
                                        >
                                            <Link
                                                href={`/admin/orders/${order.id}`}
                                                className="font-black tracking-wider text-brand-text hover:underline"
                                                dir="ltr"
                                            >
                                                {order.reference}
                                            </Link>
                                            <p className="text-xs text-ink-soft">
                                                {t(':count items', {
                                                    count: order.items,
                                                })}
                                            </p>
                                        </td>
                                        <td
                                            data-label={t('Customer')}
                                            className="px-4 py-3"
                                        >
                                            <p className="font-semibold">
                                                {order.customer}
                                            </p>
                                            <p
                                                className="text-xs text-ink-soft"
                                                dir="ltr"
                                            >
                                                {order.phone}
                                            </p>
                                        </td>
                                        <td
                                            data-label={t('Total')}
                                            className="px-4 py-3 font-bold"
                                        >
                                            <span dir="ltr">
                                                {formatMoney(
                                                    order.total,
                                                    'ILS',
                                                    'en',
                                                )}
                                            </span>
                                        </td>
                                        <td
                                            data-label={t('Order status')}
                                            className="px-4 py-3"
                                        >
                                            <Badge
                                                value={order.status}
                                                label={
                                                    options.statuses[
                                                        order.status
                                                    ] ?? order.status
                                                }
                                            />
                                        </td>
                                        <td
                                            data-label={t('Payment')}
                                            className="px-4 py-3"
                                        >
                                            <Badge
                                                value={order.payment_status}
                                                label={
                                                    options.payment_statuses[
                                                        order.payment_status
                                                    ] ?? order.payment_status
                                                }
                                            />
                                        </td>
                                        <td
                                            data-label={t('Date')}
                                            className="px-4 py-3 text-xs text-ink-soft"
                                        >
                                            {order.created_at
                                                ? formatDate(order.created_at)
                                                : ''}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <EmptyState
                        icon={<ShoppingBag size={26} aria-hidden="true" />}
                        title={t('No orders found')}
                        description={t('Try another filter or search.')}
                    />
                )}
            </section>
            <Pagination links={orders.links} />
            {selected.length > 0 && (
                <div aria-hidden="true" className="h-24 sm:hidden" />
            )}
        </StoreAdminLayout>
    );
}
