import { Head, usePage } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { useAdminText, useDateFormatter } from '@/hooks/use-admin-text';
import { formatMoney } from '@/lib/store';

type Props = {
    order: {
        reference: string;
        customer_name: string;
        phone: string;
        email: string | null;
        address: string | null;
        delivery_label: string | null;
        shipping_fee: string;
        subtotal: string;
        discount_total: string;
        coupon_code: string | null;
        points_used: number;
        points_discount: string;
        total: string;
        payment_method: string | null;
        payment_status: string;
        notes: string | null;
        created_at: string | null;
        items: {
            name: string;
            color: string | null;
            price: string;
            quantity: number;
            line_total: string;
        }[];
    };
    store: {
        name: string;
        address: string | null;
        phone: string | null;
        logo_url: string | null;
    };
};

const money = (amount: string) => formatMoney(amount, 'ILS', 'en');

/**
 * A plain page for printing or saving as PDF: an order summary and packing
 * slip. Not a tax invoice; that comes from the accounting system.
 */
export default function OrderInvoice({ order, store }: Props) {
    const t = useAdminText();
    const formatDate = useDateFormatter();
    const { direction } = usePage().props;

    return (
        <div
            dir={direction}
            className="min-h-screen bg-white text-black print:min-h-0"
        >
            <Head
                title={t('Order :reference', { reference: order.reference })}
            />
            <div className="mx-auto max-w-3xl p-8 print:p-0">
                <div className="mb-6 flex justify-end print:hidden">
                    <button
                        type="button"
                        onClick={() => window.print()}
                        className="inline-flex items-center gap-2 rounded-lg bg-black px-5 py-2.5 text-sm font-bold text-white"
                    >
                        <Printer size={16} aria-hidden="true" />
                        {t('Print')}
                    </button>
                </div>

                <header className="flex items-start justify-between gap-6 border-b-2 border-black pb-5">
                    <div>
                        {store.logo_url && (
                            <img
                                src={store.logo_url}
                                alt=""
                                className="mb-2 h-12 w-auto"
                            />
                        )}
                        <p className="text-xl font-black">{store.name}</p>
                        {store.address && (
                            <p className="text-sm whitespace-pre-line">
                                {store.address}
                            </p>
                        )}
                        {store.phone && (
                            <p className="text-sm" dir="ltr">
                                {store.phone}
                            </p>
                        )}
                    </div>
                    <div className="text-end">
                        <p className="text-2xl font-black">
                            {t('Order summary')}
                        </p>
                        <p className="font-mono text-lg tracking-widest">
                            {order.reference}
                        </p>
                        {order.created_at && (
                            <p className="text-sm">
                                {formatDate(order.created_at)}
                            </p>
                        )}
                    </div>
                </header>

                <section className="mt-5 grid grid-cols-2 gap-6 text-sm">
                    <div>
                        <p className="font-bold">{t('Customer')}</p>
                        <p>{order.customer_name}</p>
                        <p dir="ltr" className="text-start">
                            {order.phone}
                        </p>
                        {order.email && <p>{order.email}</p>}
                    </div>
                    <div>
                        <p className="font-bold">
                            {order.delivery_label ?? t('Shipping address')}
                        </p>
                        {order.address && (
                            <p className="whitespace-pre-line">
                                {order.address}
                            </p>
                        )}
                    </div>
                </section>

                <table className="mt-6 w-full border-collapse text-sm">
                    <thead>
                        <tr className="border-b border-black">
                            <th className="py-2 text-start">{t('Item')}</th>
                            <th className="py-2 text-start">{t('Price')}</th>
                            <th className="py-2 text-start">{t('Quantity')}</th>
                            <th className="py-2 text-end">{t('Total')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {order.items.map((item, index) => (
                            <tr
                                key={index}
                                className="border-b border-gray-300"
                            >
                                <td className="py-2">
                                    {item.name}
                                    {item.color ? ` (${item.color})` : ''}
                                </td>
                                <td className="py-2" dir="ltr">
                                    {money(item.price)}
                                </td>
                                <td className="py-2">{item.quantity}</td>
                                <td className="py-2 text-end" dir="ltr">
                                    {money(item.line_total)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>

                <dl className="ms-auto mt-4 grid w-72 gap-1 text-sm">
                    <Line label={t('Subtotal')} value={money(order.subtotal)} />
                    {Number(order.discount_total) > 0 && (
                        <Line
                            label={`${t('Discount')}${order.coupon_code ? ` (${order.coupon_code})` : ''}`}
                            value={`-${money(order.discount_total)}`}
                        />
                    )}
                    {order.points_used > 0 && (
                        <Line
                            label={t(':points points used', {
                                points: order.points_used,
                            })}
                            value={`-${money(order.points_discount)}`}
                        />
                    )}
                    {order.delivery_label && (
                        <Line
                            label={order.delivery_label}
                            value={money(order.shipping_fee)}
                        />
                    )}
                    <div className="flex justify-between border-t-2 border-black pt-1 text-base font-black">
                        <dt>{t('Order total')}</dt>
                        <dd dir="ltr">{money(order.total)}</dd>
                    </div>
                </dl>

                {order.notes && (
                    <p className="mt-6 border border-gray-400 p-3 text-sm">
                        <span className="font-bold">
                            {t('Customer notes')}:
                        </span>{' '}
                        {order.notes}
                    </p>
                )}
                <p className="mt-10 text-center text-xs text-gray-600">
                    {t('This is an order summary, not a tax invoice.')}
                </p>
            </div>
        </div>
    );
}

function Line({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex justify-between gap-4">
            <dt>{label}</dt>
            <dd dir="ltr">{value}</dd>
        </div>
    );
}
