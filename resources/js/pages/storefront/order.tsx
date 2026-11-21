import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    CheckCircle2,
    Clock,
    CreditCard,
    MessageCircle,
    RotateCcw,
} from 'lucide-react';
import { useTranslations } from '@/hooks/use-translations';
import StorefrontLayout from '@/layouts/storefront-layout';
import { formatMoney } from '@/lib/store';

type Order = {
    reference: string;
    status: string;
    customer_name: string;
    subtotal: string;
    discount: string;
    total: string;
    has_estimate: boolean;
    items: {
        name: string;
        color: string | null;
        quantity: number;
        line_total: string;
    }[];
    whatsapp_url: string | null;
    online: boolean;
    can_pay: boolean;
    pay_url: string;
    expires_in_hours: number;
    points_used: number;
    points_discount: string;
    points_to_earn: number;
    status_key: string;
    payment_status: string;
    delivery: string | null;
    shipping_fee: string;
    timeline: { label: string; at: string | null }[];
};

export default function StorefrontOrder({ order }: { order: Order }) {
    const { locale, flash, errors } = usePage().props as ReturnType<
        typeof usePage
    >['props'] & { errors: Record<string, string> };
    const awaitingPayment = order.online && order.payment_status !== 'paid';
    const paymentError = flash.error ?? errors.payment;
    const t = useTranslations();
    const money = (amount: string) => formatMoney(amount, 'ILS', 'en');
    const estimate = order.has_estimate ? '≈ ' : '';
    const date = new Intl.DateTimeFormat(`${locale}-u-nu-latn`, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
    const pending = order.status_key === 'pending';

    return (
        <StorefrontLayout>
            <Head title={t('checkout.order_placed')} />
            <main className="mx-auto max-w-xl px-4 py-8 sm:py-14">
                <div className="rounded-panel border border-line bg-panel p-6 text-center sm:p-8">
                    {awaitingPayment ? (
                        <Clock
                            size={56}
                            aria-hidden="true"
                            className="mx-auto text-amber-600"
                        />
                    ) : (
                        <CheckCircle2
                            size={56}
                            aria-hidden="true"
                            className="badge-pop mx-auto text-emerald-600"
                        />
                    )}
                    <h1 className="mt-3 text-2xl font-black">
                        {t('checkout.order_placed')}
                    </h1>
                    <p className="mt-2 text-sm leading-6 text-ink-soft">
                        {t('checkout.order_placed_hint')}
                    </p>
                    <p className="mt-4 text-sm text-ink-soft">
                        {t('checkout.reference')}
                    </p>
                    <p className="text-xl font-black tracking-widest" dir="ltr">
                        {order.reference}
                    </p>
                    <p className="mt-2 text-sm">
                        {t('checkout.status')}:{' '}
                        <span className="font-bold">{order.status}</span>
                    </p>

                    {order.can_pay && (
                        <>
                            <p className="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900">
                                {order.payment_status === 'failed'
                                    ? t('payments.failed_retry')
                                    : t('payments.waiting')}
                            </p>
                            {paymentError && (
                                <p
                                    role="alert"
                                    className="mt-2 text-sm font-semibold text-rose-700"
                                >
                                    {paymentError}
                                </p>
                            )}
                            <button
                                type="button"
                                onClick={() => router.post(order.pay_url)}
                                className="mt-4 inline-flex h-12 w-full items-center justify-center gap-2 rounded-btn bg-brand font-bold text-on-brand transition hover:bg-brand-hover"
                            >
                                <CreditCard size={19} aria-hidden="true" />
                                {t('payments.pay_now')}
                            </button>
                        </>
                    )}
                    {order.online && order.payment_status === 'paid' && (
                        <p className="mt-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800">
                            {t('payments.paid')}
                        </p>
                    )}
                    {pending && order.whatsapp_url && (
                        <a
                            href={order.whatsapp_url ?? undefined}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="mt-6 inline-flex h-12 w-full items-center justify-center gap-2 rounded-btn bg-emerald-600 font-bold text-white transition hover:bg-emerald-700"
                        >
                            <MessageCircle size={19} aria-hidden="true" />
                            {t('checkout.continue_whatsapp')}
                        </a>
                    )}
                    {order.points_to_earn > 0 && (
                        <p className="mt-3 rounded-lg bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-800">
                            {t('points.order_earn', {
                                points: order.points_to_earn,
                            })}
                        </p>
                    )}
                    {order.expires_in_hours > 0 && (
                        <p className="mt-3 text-xs leading-5 text-ink-soft">
                            {t('checkout.expires_notice', {
                                hours: order.expires_in_hours,
                            })}
                        </p>
                    )}
                    {!pending && (
                        <button
                            type="button"
                            onClick={() =>
                                router.post(
                                    `/${locale}/orders/${order.reference}/reorder`,
                                )
                            }
                            className="mt-4 inline-flex h-11 w-full items-center justify-center gap-2 rounded-btn border border-line font-bold transition hover:border-brand hover:text-brand-text"
                        >
                            <RotateCcw size={17} aria-hidden="true" />
                            {t('reorder.button')}
                        </button>
                    )}
                    <Link
                        href={`/${locale}/products`}
                        className="mt-3 block py-2 text-sm font-bold text-brand-text hover:underline"
                    >
                        {t('store.keep_shopping')}
                    </Link>
                </div>

                <section className="mt-5 rounded-panel border border-line bg-panel p-5">
                    <h2 className="font-black">{t('checkout.order_items')}</h2>
                    <ul className="mt-3 divide-y divide-line text-sm">
                        {order.items.map((item, index) => (
                            <li
                                key={`${item.name}-${index}`}
                                className="flex justify-between gap-4 py-2"
                            >
                                <span>
                                    {item.name}
                                    {item.color && (
                                        <span className="text-ink-soft">
                                            {' '}
                                            ({item.color})
                                        </span>
                                    )}{' '}
                                    × {item.quantity}
                                </span>
                                <span className="font-bold" dir="ltr">
                                    {estimate}
                                    {money(item.line_total)}
                                </span>
                            </li>
                        ))}
                    </ul>
                    {Number(order.discount) > 0 && (
                        <div className="mt-3 flex justify-between text-sm text-rose-700">
                            <span>{t('checkout.discount')}</span>
                            <span dir="ltr">-{money(order.discount)}</span>
                        </div>
                    )}
                    {order.points_used > 0 && (
                        <div className="mt-2 flex justify-between text-sm text-rose-700">
                            <span>
                                {t('points.discount', {
                                    points: order.points_used,
                                })}
                            </span>
                            <span dir="ltr">
                                -{money(order.points_discount)}
                            </span>
                        </div>
                    )}
                    {order.delivery && (
                        <div className="mt-2 flex justify-between gap-4 text-sm">
                            <span>{order.delivery}</span>
                            <span dir="ltr">
                                {Number(order.shipping_fee) > 0
                                    ? money(order.shipping_fee)
                                    : '—'}
                            </span>
                        </div>
                    )}
                    <div className="mt-3 flex justify-between border-t border-line pt-3 text-lg font-black">
                        <span>{t('checkout.total')}</span>
                        <span dir="ltr">
                            {estimate}
                            {money(order.total)}
                        </span>
                    </div>
                </section>

                {order.timeline.length > 0 && (
                    <section className="mt-5 rounded-panel border border-line bg-panel p-5">
                        <h2 className="font-black">
                            {t('order_updates.timeline')}
                        </h2>
                        <ol className="mt-3 space-y-3 border-s-2 border-line ps-4 text-sm">
                            {order.timeline.map((event, index) => (
                                <li key={index} className="relative">
                                    <span
                                        aria-hidden="true"
                                        className="absolute -start-[1.42rem] top-1 h-3 w-3 rounded-full border-2 border-panel bg-brand"
                                    />
                                    <p className="font-bold">{event.label}</p>
                                    {event.at && (
                                        <p className="text-xs text-ink-soft">
                                            {date.format(new Date(event.at))}
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ol>
                    </section>
                )}
            </main>
        </StorefrontLayout>
    );
}
