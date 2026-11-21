import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import {
    PageHeader,
    Pagination,
    primaryButton,
} from '@/components/admin/admin-ui';
import { useAdminText, useDateFormatter } from '@/hooks/use-admin-text';
import StoreAdminLayout from '@/layouts/store-admin-layout';
import type { PageLink } from '@/types';

type Product = {
    id: number;
    sku: string | null;
    name: string;
    stock_quantity: number;
};
type Movement = {
    id: number;
    product: string;
    actor: string;
    direction: 'increase' | 'decrease';
    quantity_delta: number;
    stock_before: number;
    stock_after: number;
    reason: string;
    created_at: string;
};
type Paginator<T> = { data: T[]; links: PageLink[]; total: number };

function AdjustmentForm({ product }: { product: Product }) {
    const t = useAdminText();
    const form = useForm<{
        direction: 'increase' | 'decrease';
        quantity: number;
        reason: string;
    }>({
        direction: 'increase',
        quantity: 1,
        reason: '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(`/admin/inventory/${product.id}/adjust`, {
            preserveScroll: true,
            onSuccess: () => form.reset('quantity', 'reason'),
        });
    }

    return (
        <form
            onSubmit={submit}
            className="grid gap-2 sm:grid-cols-[130px_100px_minmax(160px,1fr)_auto]"
        >
            <label className="sr-only" htmlFor={`direction-${product.id}`}>
                {t('Adjustment type for :name', { name: product.name })}
            </label>
            <select
                id={`direction-${product.id}`}
                value={form.data.direction}
                onChange={(event) =>
                    form.setData(
                        'direction',
                        event.target.value as 'increase' | 'decrease',
                    )
                }
                className="admin-input"
            >
                <option value="increase">{t('Add stock')}</option>
                <option value="decrease">{t('Remove stock')}</option>
            </select>
            <label className="sr-only" htmlFor={`quantity-${product.id}`}>
                {t('Quantity for :name', { name: product.name })}
            </label>
            <input
                id={`quantity-${product.id}`}
                type="number"
                min="1"
                max="4294967295"
                step="1"
                required
                value={form.data.quantity}
                onChange={(event) =>
                    form.setData('quantity', Number(event.target.value))
                }
                className="admin-input"
            />
            <label className="sr-only" htmlFor={`reason-${product.id}`}>
                {t('Adjustment reason for :name', { name: product.name })}
            </label>
            <input
                id={`reason-${product.id}`}
                required
                maxLength={500}
                value={form.data.reason}
                onChange={(event) => form.setData('reason', event.target.value)}
                placeholder={t('Reason for adjustment')}
                className="admin-input"
            />
            <button
                type="submit"
                disabled={form.processing}
                className={primaryButton}
            >
                {t(form.processing ? 'Saving…' : 'Adjust')}
            </button>
            {Object.values(form.errors).map((error) => (
                <p
                    key={error}
                    className="text-xs font-medium text-rose-700 sm:col-span-4"
                >
                    {error}
                </p>
            ))}
        </form>
    );
}

export default function InventoryIndex({
    products,
    movements,
}: {
    products: Paginator<Product>;
    movements: Paginator<Movement>;
}) {
    const t = useAdminText();
    const formatDate = useDateFormatter();

    return (
        <StoreAdminLayout title={t('Inventory')}>
            <PageHeader
                eyebrow={t('Stock control')}
                title={t('Inventory')}
                description={t(
                    'Adjust quantities with a required reason. Every change is recorded with its before and after balance.',
                )}
            />

            <section aria-labelledby="stock-title">
                <div className="mb-4 flex items-end justify-between gap-4">
                    <h2 id="stock-title" className="text-xl font-extrabold">
                        {t('Product stock')}
                    </h2>
                    <span className="text-sm text-ink-soft">
                        {t(':count products', { count: products.total })}
                    </span>
                </div>
                <div className="space-y-3">
                    {products.data.length > 0 ? (
                        products.data.map((product) => (
                            <article
                                key={product.id}
                                className="rounded-panel border border-line bg-panel p-4 sm:p-5"
                            >
                                <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h3 className="font-bold text-ink">
                                            {product.name}
                                        </h3>
                                        <p className="mt-1 text-xs text-ink-soft">
                                            {product.sku
                                                ? `${t('SKU')}: ${product.sku}`
                                                : `${t('Product')} #${product.id}`}
                                        </p>
                                    </div>
                                    <p className="rounded-full bg-surface px-3 py-1 text-sm font-bold text-ink/80">
                                        {t('On hand: :count', {
                                            count: product.stock_quantity,
                                        })}
                                    </p>
                                </div>
                                <AdjustmentForm product={product} />
                            </article>
                        ))
                    ) : (
                        <div className="rounded-panel border border-dashed border-line bg-panel px-6 py-12 text-center text-ink-soft">
                            {t('No products have been created yet.')}
                        </div>
                    )}
                </div>
                <Pagination links={products.links} />
            </section>

            <section aria-labelledby="movement-title" className="mt-12">
                <div className="mb-4 flex items-end justify-between gap-4">
                    <div>
                        <h2
                            id="movement-title"
                            className="text-xl font-extrabold"
                        >
                            {t('Recent stock movements')}
                        </h2>
                        <p className="mt-1 text-sm text-ink-soft">
                            {t(
                                'An auditable record of manual inventory changes.',
                            )}
                        </p>
                    </div>
                </div>
                {movements.data.length > 0 ? (
                    <div className="overflow-x-auto rounded-panel border border-line bg-panel">
                        <table className="admin-table w-full min-w-[720px] text-start text-sm">
                            <thead className="bg-surface text-xs tracking-wider text-ink-soft uppercase">
                                <tr>
                                    <th className="px-4 py-3 text-start">
                                        {t('Product')}
                                    </th>
                                    <th className="px-4 py-3 text-start">
                                        {t('Change')}
                                    </th>
                                    <th className="px-4 py-3 text-start">
                                        {t('Balance')}
                                    </th>
                                    <th className="px-4 py-3 text-start">
                                        {t('Reason')}
                                    </th>
                                    <th className="px-4 py-3 text-start">
                                        {t('Staff')}
                                    </th>
                                    <th className="px-4 py-3 text-start">
                                        {t('Date')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {movements.data.map((movement) => (
                                    <tr key={movement.id}>
                                        <td
                                            data-cell="primary"
                                            className="px-4 py-3 font-semibold"
                                        >
                                            {movement.product}
                                        </td>
                                        <td
                                            data-label={t('Change')}
                                            className={`px-4 py-3 font-bold ${movement.quantity_delta > 0 ? 'text-emerald-700' : 'text-rose-700'}`}
                                        >
                                            {movement.quantity_delta > 0
                                                ? '+'
                                                : ''}
                                            {movement.quantity_delta}
                                        </td>
                                        <td
                                            data-label={t('Balance')}
                                            className="px-4 py-3 text-ink-soft"
                                        >
                                            {movement.stock_before} →{' '}
                                            {movement.stock_after}
                                        </td>
                                        <td
                                            data-label={t('Reason')}
                                            className="max-w-56 px-4 py-3 text-ink-soft"
                                        >
                                            {movement.reason}
                                        </td>
                                        <td
                                            data-label={t('Staff')}
                                            className="px-4 py-3 text-ink-soft"
                                        >
                                            {movement.actor}
                                        </td>
                                        <td
                                            data-label={t('Date')}
                                            className="px-4 py-3 text-ink-soft"
                                        >
                                            <time
                                                dateTime={movement.created_at}
                                            >
                                                {formatDate(
                                                    movement.created_at,
                                                )}
                                            </time>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <div className="rounded-panel border border-dashed border-line bg-panel px-6 py-12 text-center text-ink-soft">
                        {t('Stock adjustments will appear here.')}
                    </div>
                )}
                <Pagination links={movements.links} />
            </section>
        </StoreAdminLayout>
    );
}
