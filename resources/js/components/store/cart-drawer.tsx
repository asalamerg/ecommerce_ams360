import { Link, usePage } from '@inertiajs/react';
import { Check, ShoppingCart, Trash2, X } from 'lucide-react';
import { useEffect } from 'react';
import { LineColor } from '@/components/store/color-dots';
import {
    closeCartDrawer,
    removeCartLine,
    updateCartLine,
    useCartDrawerOpen,
} from '@/hooks/use-cart';
import { useTranslations } from '@/hooks/use-translations';
import { formatMoney } from '@/lib/store';
import { cn } from '@/lib/utils';
import QuantityStepper from './quantity-stepper';

export default function CartDrawer() {
    const { cart, locale } = usePage().props;
    const t = useTranslations();
    const open = useCartDrawerOpen();

    useEffect(() => {
        if (!open) {
            return;
        }

        const close = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                closeCartDrawer();
            }
        };

        window.addEventListener('keydown', close);

        return () => window.removeEventListener('keydown', close);
    }, [open]);

    const items = cart?.items ?? [];

    return (
        <>
            <button
                type="button"
                aria-label={t('store.keep_shopping')}
                tabIndex={open ? 0 : -1}
                onClick={closeCartDrawer}
                className={cn(
                    'fixed inset-0 z-50 bg-black/50 transition-opacity duration-300',
                    open ? 'opacity-100' : 'pointer-events-none opacity-0',
                )}
            />
            <aside
                role="dialog"
                aria-modal="true"
                aria-label={t('store.cart')}
                aria-hidden={!open}
                inert={!open}
                className={cn(
                    'fixed inset-y-0 end-0 z-50 flex w-full max-w-sm flex-col bg-panel text-ink shadow-2xl transition-transform duration-300 motion-reduce:transition-none',
                    open
                        ? 'translate-x-0'
                        : 'translate-x-full rtl:-translate-x-full',
                )}
            >
                <div className="flex items-center justify-between border-b border-line px-5 py-4">
                    <h2 className="flex items-center gap-2 font-black">
                        <span className="grid h-6 w-6 place-items-center rounded-full bg-emerald-600 text-white">
                            <Check size={14} aria-hidden="true" />
                        </span>
                        {t('store.added')}
                    </h2>
                    <button
                        type="button"
                        aria-label={t('store.keep_shopping')}
                        onClick={closeCartDrawer}
                        className="grid h-9 w-9 place-items-center rounded-full hover:bg-surface"
                    >
                        <X size={18} aria-hidden="true" />
                    </button>
                </div>

                <div className="flex-1 overflow-y-auto">
                    {items.length ? (
                        <ul className="divide-y divide-line">
                            {items.map((item) => (
                                <li
                                    key={item.line_key}
                                    className="flex gap-3 p-4"
                                >
                                    <div className="h-20 w-20 shrink-0 overflow-hidden rounded-xl bg-surface">
                                        {item.image_url && (
                                            <img
                                                src={item.image_url}
                                                alt=""
                                                loading="lazy"
                                                className="h-full w-full object-contain p-1"
                                            />
                                        )}
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <p className="line-clamp-2 text-sm font-semibold">
                                            {item.name}
                                        </p>
                                        <LineColor color={item.color} />
                                        <p
                                            className="mt-1 text-sm font-black"
                                            dir="ltr"
                                        >
                                            <span className="block text-start rtl:text-end">
                                                {formatMoney(
                                                    item.line_total,
                                                    item.currency,
                                                    'en',
                                                )}
                                            </span>
                                        </p>
                                        <div className="mt-2 flex items-center justify-between">
                                            {item.available ? (
                                                <QuantityStepper
                                                    value={item.quantity}
                                                    max={item.max_quantity}
                                                    onChange={(quantity) =>
                                                        updateCartLine(
                                                            item.id,
                                                            quantity,
                                                            item.color?.id,
                                                        )
                                                    }
                                                />
                                            ) : (
                                                <span className="text-xs font-semibold text-rose-700">
                                                    {t(
                                                        'store.unavailable_line',
                                                    )}
                                                </span>
                                            )}
                                            <button
                                                type="button"
                                                aria-label={t('store.remove')}
                                                title={t('store.remove')}
                                                onClick={() =>
                                                    removeCartLine(
                                                        item.id,
                                                        item.color?.id,
                                                    )
                                                }
                                                className="grid h-9 w-9 place-items-center rounded-full text-ink-soft hover:bg-surface hover:text-rose-700"
                                            >
                                                <Trash2
                                                    size={16}
                                                    aria-hidden="true"
                                                />
                                            </button>
                                        </div>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <div className="px-6 py-16 text-center text-ink-soft">
                            <ShoppingCart
                                size={36}
                                aria-hidden="true"
                                className="mx-auto mb-3"
                            />
                            {t('store.cart_empty')}
                        </div>
                    )}
                </div>

                <div className="border-t border-line p-4 pb-[calc(1rem+env(safe-area-inset-bottom))]">
                    <div className="mb-3 flex items-center justify-between font-black">
                        <span>{t('store.subtotal')}</span>
                        <span dir="ltr">
                            {formatMoney(
                                cart?.subtotal ?? 0,
                                cart?.currency ?? 'ILS',
                                'en',
                            )}
                        </span>
                    </div>
                    <Link
                        href={`/${locale}/cart`}
                        onClick={closeCartDrawer}
                        className="block rounded-btn bg-brand py-3 text-center font-bold text-on-brand transition hover:bg-brand-hover"
                    >
                        {t('store.view_cart')}
                    </Link>
                    <button
                        type="button"
                        onClick={closeCartDrawer}
                        className="mt-2 w-full py-2 text-sm font-bold text-ink-soft hover:text-ink"
                    >
                        {t('store.keep_shopping')}
                    </button>
                </div>
            </aside>
        </>
    );
}
