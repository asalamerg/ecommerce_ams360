import { Link, useForm, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    MessageCircle,
    Printer,
    StickyNote,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { Badge } from '@/components/admin/order-badge';
import type { OrderOptions } from '@/components/admin/order-badge';
import {
    PageHeader,
    panel,
    primaryButton,
    secondaryButton,
} from '@/components/admin/admin-ui';
import { useAdminText, useDateFormatter } from '@/hooks/use-admin-text';
import StoreAdminLayout from '@/layouts/store-admin-layout';
import { formatMoney } from '@/lib/store';

type Event = {
    id: number;
    type: string;
    from: string | null;
    to: string | null;
    note: string | null;
    by: string | null;
    at: string | null;
};

type Order = {
    id: number;
    reference: string;
    customer_name: string;
    phone: string;
    email: string | null;
    address: string | null;
    city: string | null;
    notes: string | null;
    locale: string;
    status: string;
    payment_status: string;
    payment_method: string | null;
    payment_reference: string | null;
    delivery_method: string;
    delivery_label: string | null;
    shipping_fee: string;
    subtotal: string;
    discount_total: string;
    coupon_code: string | null;
    points_used: number;
    points_discount: string;
    points_earned: number;
    total: string;
    item_count: number;
    created_at: string | null;
    items: {
        id: number;
        product_id: number | null;
        name: string;
        color: string | null;
        price: string;
        original_price: string | null;
        quantity: number;
        line_total: string;
        price_is_estimate: boolean;
    }[];
    events: Event[];
    account: { id: number; name: string } | null;
    payment_is_manual: boolean;
    payments: {
        id: number;
        gateway: string;
        status: string;
        amount: string;
        reference: string | null;
        reason: string | null;
        at: string | null;
    }[];
    can_refund: boolean;
};

const money = (amount: string) => formatMoney(amount, 'ILS', 'en');

export default function AdminOrder({
    order,
    options,
    whatsappReady,
}: {
    order: Order;
    options: OrderOptions;
    whatsappReady: boolean;
}) {
    const t = useAdminText();
    const formatDate = useDateFormatter();
    const { direction } = usePage().props;
    const BackIcon = direction === 'rtl' ? ArrowRight : ArrowLeft;
    const statusForm = useForm({
        status: order.status,
        payment_status: order.payment_status,
        notify_customer: true,
    });
    const noteForm = useForm({ note: '' });
    const refundForm = useForm({});
    const label = (value: string | null) =>
        value === null
            ? ''
            : (options.statuses[value] ??
              options.payment_statuses[value] ??
              value);

    function saveStatus(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        statusForm.put(`/admin/orders/${order.id}`, { preserveScroll: true });
    }

    function addNote(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        noteForm.post(`/admin/orders/${order.id}/notes`, {
            preserveScroll: true,
            onSuccess: () => noteForm.reset(),
        });
    }

    function describe(entry: Event): string {
        switch (entry.type) {
            case 'created':
                return t('Order placed');
            case 'status':
                return t('Status: :from → :to', {
                    from: label(entry.from),
                    to: label(entry.to),
                });
            case 'payment_status':
                return t('Payment: :from → :to', {
                    from: label(entry.from),
                    to: label(entry.to),
                });
            case 'customer_emailed':
                return t('Customer e-mailed: :status', {
                    status: label(entry.to),
                });
            case 'customer_whatsapp':
                return t('WhatsApp message opened for the customer');
            case 'payment':
                return t('Payment provider: :status', {
                    status: label(entry.to),
                });
            default:
                return t('Note');
        }
    }

    return (
        <StoreAdminLayout
            title={t('Order :reference', { reference: order.reference })}
        >
            <Link
                href="/admin/orders"
                className="mb-3 inline-flex items-center gap-1 text-sm font-bold text-brand-text hover:underline"
            >
                <BackIcon size={16} aria-hidden="true" />
                {t('Orders')}
            </Link>
            <PageHeader
                eyebrow={order.created_at ? formatDate(order.created_at) : ''}
                title={t('Order :reference', { reference: order.reference })}
            >
                <div className="flex flex-wrap items-center gap-2">
                    <Badge value={order.status} label={label(order.status)} />
                    <Badge
                        value={order.payment_status}
                        label={label(order.payment_status)}
                    />
                    {whatsappReady && (
                        <a
                            href={`/admin/orders/${order.id}/whatsapp`}
                            target="_blank"
                            rel="noopener noreferrer"
                            className={secondaryButton}
                        >
                            <MessageCircle size={16} aria-hidden="true" />
                            {t('WhatsApp the customer')}
                        </a>
                    )}
                    <a
                        href={`/admin/orders/${order.id}/invoice`}
                        target="_blank"
                        rel="noopener noreferrer"
                        className={secondaryButton}
                    >
                        <Printer size={16} aria-hidden="true" />
                        {t('Print invoice')}
                    </a>
                </div>
            </PageHeader>

            <div className="grid gap-5 lg:grid-cols-[1fr_340px]">
                <div className="grid content-start gap-5">
                    <section className={`${panel} overflow-hidden`}>
                        <h2 className="border-b border-line px-5 py-4 text-lg font-extrabold">
                            {t('Order items')}
                        </h2>
                        <div className="overflow-x-auto">
                            <table className="admin-table w-full min-w-[480px] text-sm">
                                <thead className="bg-surface text-xs text-ink-soft">
                                    <tr>
                                        <th className="px-5 py-3 text-start">
                                            {t('Item')}
                                        </th>
                                        <th className="px-5 py-3 text-start">
                                            {t('Price')}
                                        </th>
                                        <th className="px-5 py-3 text-start">
                                            {t('Quantity')}
                                        </th>
                                        <th className="px-5 py-3 text-end">
                                            {t('Total')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-line">
                                    {order.items.map((item) => (
                                        <tr key={item.id}>
                                            <td
                                                data-cell="primary"
                                                className="px-5 py-3"
                                            >
                                                {item.product_id ? (
                                                    <Link
                                                        href={`/admin/products/${item.product_id}/edit`}
                                                        className="font-semibold hover:underline"
                                                    >
                                                        {item.name}
                                                    </Link>
                                                ) : (
                                                    <span className="font-semibold">
                                                        {item.name}
                                                    </span>
                                                )}
                                                {item.color && (
                                                    <span className="text-ink-soft">
                                                        {' '}
                                                        ({item.color})
                                                    </span>
                                                )}
                                                {item.price_is_estimate && (
                                                    <span className="ms-2 rounded bg-amber-100 px-1.5 text-xs font-bold text-amber-900">
                                                        {t('Estimated price')}
                                                    </span>
                                                )}
                                            </td>
                                            <td
                                                className="px-5 py-3"
                                                data-label={t('Price')}
                                            >
                                                <span dir="ltr">
                                                    {item.original_price && (
                                                        <s className="me-1.5 text-xs text-ink-soft">
                                                            {money(
                                                                item.original_price,
                                                            )}
                                                        </s>
                                                    )}
                                                    {money(item.price)}
                                                </span>
                                            </td>
                                            <td
                                                className="px-5 py-3"
                                                data-label={t('Quantity')}
                                            >
                                                {item.quantity}
                                            </td>
                                            <td
                                                className="px-5 py-3 text-end font-bold"
                                                data-label={t('Total')}
                                            >
                                                <span dir="ltr">
                                                    {money(item.line_total)}
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <dl className="grid gap-1.5 border-t border-line px-5 py-4 text-sm">
                            <Row
                                label={t('Subtotal')}
                                value={money(order.subtotal)}
                            />
                            {Number(order.discount_total) > 0 && (
                                <Row
                                    label={`${t('Discount')}${order.coupon_code ? ` (${order.coupon_code})` : ''}`}
                                    value={`-${money(order.discount_total)}`}
                                />
                            )}
                            {order.points_used > 0 && (
                                <Row
                                    label={t(':points points used', {
                                        points: order.points_used,
                                    })}
                                    value={`-${money(order.points_discount)}`}
                                />
                            )}
                            {order.delivery_label && (
                                <Row
                                    label={order.delivery_label}
                                    value={money(order.shipping_fee)}
                                />
                            )}
                            <Row
                                label={t('Order total')}
                                value={money(order.total)}
                                strong
                            />
                            {order.points_earned > 0 && (
                                <p className="text-xs text-emerald-700">
                                    {t(':points points earned', {
                                        points: order.points_earned,
                                    })}
                                </p>
                            )}
                        </dl>
                    </section>

                    <section className={`${panel} p-5`}>
                        <h2 className="text-lg font-extrabold">
                            {t('Order history')}
                        </h2>
                        <form onSubmit={addNote} className="mt-4 flex gap-2">
                            <input
                                value={noteForm.data.note}
                                onChange={(event) =>
                                    noteForm.setData('note', event.target.value)
                                }
                                maxLength={2000}
                                placeholder={t(
                                    'Internal note (customers never see it)',
                                )}
                                className="admin-input min-w-0 flex-1"
                            />
                            <button
                                type="submit"
                                disabled={
                                    noteForm.processing ||
                                    !noteForm.data.note.trim()
                                }
                                className={secondaryButton}
                            >
                                <StickyNote size={16} aria-hidden="true" />
                                {t('Add note')}
                            </button>
                        </form>
                        {noteForm.errors.note && (
                            <p className="mt-1 text-sm text-rose-700">
                                {noteForm.errors.note}
                            </p>
                        )}
                        <ol className="mt-5 space-y-4 border-s-2 border-line ps-4">
                            {order.events.map((entry) => (
                                <li key={entry.id} className="relative text-sm">
                                    <span
                                        aria-hidden="true"
                                        className={`absolute -start-[1.42rem] top-1 h-3 w-3 rounded-full border-2 border-panel ${entry.type === 'note' ? 'bg-amber-500' : 'bg-brand'}`}
                                    />
                                    <p className="font-bold">
                                        {describe(entry)}
                                    </p>
                                    {entry.note && (
                                        <p className="mt-0.5 whitespace-pre-line">
                                            {entry.type === 'note'
                                                ? entry.note
                                                : t(entry.note)}
                                        </p>
                                    )}
                                    <p className="text-xs text-ink-soft">
                                        {entry.at ? formatDate(entry.at) : ''}
                                        {' · '}
                                        {entry.by ?? t('System')}
                                    </p>
                                </li>
                            ))}
                        </ol>
                    </section>
                </div>

                <aside className="grid content-start gap-5">
                    <form
                        onSubmit={saveStatus}
                        className={`${panel} grid gap-3 p-5`}
                    >
                        <h2 className="text-lg font-extrabold">
                            {t('Update order')}
                        </h2>
                        <label className="grid gap-1.5 text-sm font-semibold">
                            {t('Order status')}
                            <select
                                value={statusForm.data.status}
                                onChange={(event) =>
                                    statusForm.setData(
                                        'status',
                                        event.target.value,
                                    )
                                }
                                className="admin-input"
                            >
                                {Object.entries(options.statuses).map(
                                    ([key, text]) => (
                                        <option
                                            key={key}
                                            value={key}
                                            disabled={
                                                order.status === 'cancelled' &&
                                                key !== 'cancelled'
                                            }
                                        >
                                            {text}
                                        </option>
                                    ),
                                )}
                            </select>
                        </label>
                        <label className="grid gap-1.5 text-sm font-semibold">
                            {t('Payment status')}
                            <select
                                value={statusForm.data.payment_status}
                                disabled={!order.payment_is_manual}
                                onChange={(event) =>
                                    statusForm.setData(
                                        'payment_status',
                                        event.target.value,
                                    )
                                }
                                className="admin-input"
                            >
                                {Object.entries(options.payment_statuses).map(
                                    ([key, text]) => (
                                        <option key={key} value={key}>
                                            {text}
                                        </option>
                                    ),
                                )}
                            </select>
                            {!order.payment_is_manual && (
                                <span className="text-xs font-normal text-ink-soft">
                                    {t('Set by the payment provider only.')}
                                </span>
                            )}
                        </label>
                        {order.email && (
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={statusForm.data.notify_customer}
                                    onChange={(event) =>
                                        statusForm.setData(
                                            'notify_customer',
                                            event.target.checked,
                                        )
                                    }
                                />
                                {t('E-mail the customer about the new status')}
                            </label>
                        )}
                        {(statusForm.errors.status ||
                            statusForm.errors.payment_status) && (
                            <p className="text-sm text-rose-700">
                                {statusForm.errors.status ||
                                    statusForm.errors.payment_status}
                            </p>
                        )}
                        <button
                            type="submit"
                            disabled={
                                statusForm.processing || !statusForm.isDirty
                            }
                            className={primaryButton}
                        >
                            {t('Save')}
                        </button>
                    </form>

                    {order.payments.length > 0 && (
                        <section className={`${panel} grid gap-3 p-5 text-sm`}>
                            <h2 className="text-lg font-extrabold">
                                {t('Online payments')}
                            </h2>
                            <ul className="grid gap-2">
                                {order.payments.map((payment) => (
                                    <li
                                        key={payment.id}
                                        className="rounded-lg border border-line p-3"
                                    >
                                        <div className="flex items-center justify-between gap-2">
                                            <span
                                                className="font-bold"
                                                dir="ltr"
                                            >
                                                {money(payment.amount)}
                                            </span>
                                            <Badge
                                                value={payment.status}
                                                label={t(
                                                    `payment.${payment.status}`,
                                                )}
                                            />
                                        </div>
                                        <p
                                            className="mt-1 text-xs break-all text-ink-soft"
                                            dir="ltr"
                                        >
                                            {payment.gateway}
                                            {payment.reference
                                                ? ` · ${payment.reference}`
                                                : ''}
                                        </p>
                                        {payment.reason && (
                                            <p className="mt-1 text-xs text-rose-700">
                                                {payment.reason}
                                            </p>
                                        )}
                                    </li>
                                ))}
                            </ul>
                            {order.can_refund && (
                                <button
                                    type="button"
                                    disabled={refundForm.processing}
                                    onClick={() => {
                                        if (
                                            window.confirm(
                                                t(
                                                    'Refund the full payment to the customer? This cannot be undone.',
                                                ),
                                            )
                                        ) {
                                            refundForm.post(
                                                `/admin/orders/${order.id}/refund`,
                                                { preserveScroll: true },
                                            );
                                        }
                                    }}
                                    className={secondaryButton}
                                >
                                    {t('Refund payment')}
                                </button>
                            )}
                            {(refundForm.errors as Record<string, string>)
                                .refund && (
                                <p className="text-rose-700">
                                    {
                                        (
                                            refundForm.errors as Record<
                                                string,
                                                string
                                            >
                                        ).refund
                                    }
                                </p>
                            )}
                        </section>
                    )}

                    <section className={`${panel} grid gap-2 p-5 text-sm`}>
                        <h2 className="text-lg font-extrabold">
                            {t('Customer')}
                        </h2>
                        <p className="font-bold">{order.customer_name}</p>
                        <a
                            href={`tel:${order.phone}`}
                            dir="ltr"
                            className="text-start text-brand-text hover:underline"
                        >
                            {order.phone}
                        </a>
                        {order.email && (
                            <a
                                href={`mailto:${order.email}`}
                                className="break-all text-brand-text hover:underline"
                            >
                                {order.email}
                            </a>
                        )}
                        {order.account && (
                            <Link
                                href={`/admin/modules/users/${order.account.id}/edit`}
                                className="text-xs font-bold text-brand-text hover:underline"
                            >
                                {t('Customer account')}
                            </Link>
                        )}
                        {(order.delivery_label || order.address) && (
                            <div className="mt-2 border-t border-line pt-2">
                                {order.delivery_label && (
                                    <p className="font-semibold">
                                        {order.delivery_label}
                                    </p>
                                )}
                                {order.address && (
                                    <p className="whitespace-pre-line text-ink-soft">
                                        {order.address}
                                    </p>
                                )}
                            </div>
                        )}
                        {order.notes && (
                            <div className="mt-2 rounded-lg bg-amber-50 p-3 text-amber-950">
                                <p className="text-xs font-bold">
                                    {t('Customer notes')}
                                </p>
                                <p className="whitespace-pre-line">
                                    {order.notes}
                                </p>
                            </div>
                        )}
                        <dl className="mt-2 grid gap-1 border-t border-line pt-2 text-xs text-ink-soft">
                            <Row
                                label={t('Payment method')}
                                value={order.payment_method ?? '—'}
                            />
                            {order.payment_reference && (
                                <Row
                                    label={t('Payment reference')}
                                    value={order.payment_reference}
                                />
                            )}
                            <Row
                                label={t('Preferred language')}
                                value={order.locale.toUpperCase()}
                            />
                        </dl>
                    </section>
                </aside>
            </div>
        </StoreAdminLayout>
    );
}

function Row({
    label,
    value,
    strong = false,
}: {
    label: string;
    value: string;
    strong?: boolean;
}) {
    return (
        <div
            className={`flex justify-between gap-4 ${strong ? 'border-t border-line pt-2 text-base font-black' : ''}`}
        >
            <dt>{label}</dt>
            <dd dir="ltr">{value}</dd>
        </div>
    );
}
