import { Link, router, usePage } from '@inertiajs/react';
import { Download, Plus, Search, Sparkles, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import {
    EmptyState,
    PageHeader,
    Pagination,
    SearchBox,
    panel,
    primaryButton,
    secondaryButton,
} from '@/components/admin/admin-ui';
import {
    DeleteButton,
    VisibilityToggle,
} from '@/components/admin/catalog-actions';
import { useAdminText } from '@/hooks/use-admin-text';
import { savedRowClass, useSavedRow } from '@/hooks/use-saved-row';
import StoreAdminLayout from '@/layouts/store-admin-layout';
import { formatMoney } from '@/lib/store';
import { cn } from '@/lib/utils';
import type { PageLink } from '@/types';

type Translation = { name: string };
type Product = {
    id: number;
    sku: string | null;
    price: string;
    sale_price: string | null;
    discount_percent: number;
    /** The offer giving the sale price, if it is not the own discount. */
    offer: string | null;
    currency: string;
    stock_quantity: number;
    is_active: boolean;
    is_featured: boolean;
    price_is_estimate: boolean;
    image_url: string | null;
    translations: Record<string, Translation>;
    category: Record<string, Translation> | null;
};
type Filter =
    | 'all'
    | 'shown'
    | 'hidden'
    | 'featured'
    | 'low'
    | 'out'
    | 'estimated'
    | 'on_sale';
type Props = {
    products: { data: Product[]; links: PageLink[]; total: number };
    search: string;
    status: Filter;
    counts: Record<Filter, number>;
};

const FILTERS: { key: Filter; label: string; tone: string }[] = [
    { key: 'all', label: 'All', tone: '' },
    { key: 'shown', label: 'Shown', tone: 'text-emerald-700' },
    { key: 'hidden', label: 'Hidden', tone: 'text-ink-soft' },
    {
        key: 'featured',
        label: 'Featured on the home page',
        tone: 'text-sky-700',
    },
    { key: 'low', label: 'Low stock', tone: 'text-amber-700' },
    { key: 'out', label: 'Out of stock', tone: 'text-rose-700' },
    { key: 'estimated', label: 'Estimated prices', tone: 'text-amber-700' },
    { key: 'on_sale', label: 'On sale', tone: 'text-rose-700' },
];

function localized(
    translations: Record<string, Translation> | null,
    locale: string,
): string | undefined {
    return (
        translations?.[locale]?.name ??
        translations?.ar?.name ??
        translations?.en?.name ??
        translations?.he?.name
    );
}

export default function ProductsIndex({
    products,
    search,
    status,
    counts,
}: Props) {
    const { locale, errors } = usePage().props as ReturnType<
        typeof usePage
    >['props'] & { errors: Record<string, string> };
    const t = useAdminText();
    const { withBack, highlight, savedRowRef } = useSavedRow();
    const [selected, setSelected] = useState<number[]>([]);
    const [discount, setDiscount] = useState({ percent: '', ends: '' });
    const fileInput = useRef<HTMLInputElement>(null);
    const allChecked =
        products.data.length > 0 &&
        products.data.every((product) => selected.includes(product.id));

    function bulk(
        action:
            | 'show'
            | 'hide'
            | 'confirm_price'
            | 'discount'
            | 'remove_discount'
            | 'feature'
            | 'unfeature',
    ) {
        router.post(
            '/admin/products/bulk',
            {
                ids: selected,
                action,
                ...(action === 'discount'
                    ? {
                          discount_percent: discount.percent,
                          discount_ends_at: discount.ends || null,
                      }
                    : {}),
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSelected([]);
                    setDiscount({ percent: '', ends: '' });
                },
            },
        );
    }

    function importFile(file: File | undefined) {
        if (file) {
            router.post(
                '/admin/products/import',
                { file },
                { forceFormData: true, preserveScroll: true },
            );
        }

        if (fileInput.current) {
            fileInput.current.value = '';
        }
    }

    return (
        <StoreAdminLayout title={t('Products')}>
            <PageHeader
                eyebrow={t('Catalog')}
                title={t('Products')}
                description={t(':count products in your catalog', {
                    count: products.total,
                })}
            >
                <div className="flex w-full flex-wrap items-center gap-3 sm:w-auto">
                    <SearchBox value={search} url="/admin/products" />
                    <a
                        href="/admin/products/export"
                        className={secondaryButton}
                    >
                        <Download size={16} aria-hidden="true" />
                        {t('Export')}
                    </a>
                    <button
                        type="button"
                        onClick={() => fileInput.current?.click()}
                        className={secondaryButton}
                        title={t(
                            'Import an edited export to change prices, visibility and stock.',
                        )}
                    >
                        <Upload size={16} aria-hidden="true" />
                        {t('Import')}
                    </button>
                    <input
                        ref={fileInput}
                        type="file"
                        accept=".csv,text/csv"
                        className="hidden"
                        onChange={(event) =>
                            importFile(event.target.files?.[0])
                        }
                    />
                    <Link
                        href={withBack('/admin/products/create')}
                        className={primaryButton}
                    >
                        <Plus size={18} aria-hidden="true" />
                        {t('Add product')}
                    </Link>
                </div>
            </PageHeader>
            {errors.file && (
                <div
                    role="alert"
                    className="mb-4 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900"
                >
                    <p className="font-bold">
                        {t(
                            'Nothing was changed. Fix these rows and import again:',
                        )}
                    </p>
                    <ul className="mt-2 list-disc space-y-0.5 ps-5">
                        {errors.file.split('\n').map((line) => (
                            <li key={line}>{line}</li>
                        ))}
                    </ul>
                </div>
            )}
            {(errors.discount_percent || errors.discount_ends_at) && (
                <p
                    role="alert"
                    className="mb-4 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-900"
                >
                    {errors.discount_percent ?? errors.discount_ends_at}
                </p>
            )}
            {selected.length > 0 && (
                <div
                    className={`${panel} mb-4 flex flex-wrap items-center gap-2 p-3 max-sm:fixed max-sm:inset-x-3 max-sm:bottom-(--admin-bottom) max-sm:z-30 max-sm:mb-0 max-sm:flex-nowrap max-sm:overflow-x-auto max-sm:shadow-xl sm:sticky sm:top-16 sm:z-10 [&>*]:shrink-0`}
                >
                    <span className="me-2 text-sm font-bold">
                        {t(':count selected', { count: selected.length })}
                    </span>
                    <button
                        type="button"
                        onClick={() => bulk('show')}
                        className={secondaryButton}
                    >
                        {t('Show in store')}
                    </button>
                    <button
                        type="button"
                        onClick={() => bulk('hide')}
                        className={secondaryButton}
                    >
                        {t('Hide from store')}
                    </button>
                    <button
                        type="button"
                        onClick={() => bulk('confirm_price')}
                        className={secondaryButton}
                    >
                        {t('Mark prices confirmed')}
                    </button>
                    <button
                        type="button"
                        onClick={() => bulk('feature')}
                        className={secondaryButton}
                    >
                        <Sparkles size={16} aria-hidden="true" />
                        {t('Feature on homepage')}
                    </button>
                    <button
                        type="button"
                        onClick={() => bulk('unfeature')}
                        className={secondaryButton}
                    >
                        {t('Remove from featured')}
                    </button>
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            bulk('discount');
                        }}
                        className="flex flex-wrap items-center gap-2 border-line ps-2 max-sm:flex-nowrap sm:border-s"
                    >
                        <input
                            type="number"
                            min="1"
                            max="90"
                            step="1"
                            required
                            value={discount.percent}
                            onChange={(event) =>
                                setDiscount((current) => ({
                                    ...current,
                                    percent: event.target.value,
                                }))
                            }
                            aria-label={t('Discount (%)')}
                            placeholder="%"
                            className="admin-input h-10 w-20"
                        />
                        <input
                            type="date"
                            value={discount.ends}
                            min={new Date().toISOString().slice(0, 10)}
                            onChange={(event) =>
                                setDiscount((current) => ({
                                    ...current,
                                    ends: event.target.value,
                                }))
                            }
                            aria-label={t('Discount ends on')}
                            className="admin-input h-10 w-40"
                        />
                        <button type="submit" className={secondaryButton}>
                            {t('Apply discount')}
                        </button>
                        <button
                            type="button"
                            onClick={() => bulk('remove_discount')}
                            className={secondaryButton}
                        >
                            {t('Remove discount')}
                        </button>
                    </form>
                    <button
                        type="button"
                        onClick={() => setSelected([])}
                        className="px-3 text-sm font-bold text-ink-soft hover:underline"
                    >
                        {t('Cancel')}
                    </button>
                </div>
            )}
            <nav
                aria-label={t('Filter products')}
                className="mb-4 flex flex-wrap gap-2 pb-1"
            >
                {FILTERS.map((filter) => (
                    <Link
                        key={filter.key}
                        href={`/admin/products?${new URLSearchParams({
                            ...(filter.key === 'all'
                                ? {}
                                : { status: filter.key }),
                            ...(search ? { q: search } : {}),
                        }).toString()}`}
                        preserveScroll
                        aria-current={
                            status === filter.key ? 'page' : undefined
                        }
                        className={cn(
                            'inline-flex shrink-0 items-center gap-2 rounded-full border px-3.5 py-1.5 text-sm font-bold transition',
                            status === filter.key
                                ? 'border-brand bg-brand text-on-brand'
                                : 'border-line bg-panel hover:border-brand',
                        )}
                    >
                        <span
                            className={status === filter.key ? '' : filter.tone}
                        >
                            {t(filter.label)}
                        </span>
                        <span
                            className={cn(
                                'rounded-full px-1.5 text-xs',
                                status === filter.key
                                    ? 'bg-on-brand/20'
                                    : 'bg-surface text-ink-soft',
                            )}
                        >
                            {counts[filter.key]}
                        </span>
                    </Link>
                ))}
            </nav>
            <div className={`${panel} overflow-hidden`}>
                {products.data.length > 0 ? (
                    <div className="overflow-x-auto">
                        <table className="admin-table w-full min-w-[720px] text-start text-sm">
                            <thead className="bg-surface text-xs text-ink-soft">
                                <tr>
                                    <th className="w-10 py-3.5 ps-5">
                                        <input
                                            type="checkbox"
                                            aria-label={t('Select all')}
                                            checked={allChecked}
                                            onChange={() =>
                                                setSelected(
                                                    allChecked
                                                        ? []
                                                        : products.data.map(
                                                              (product) =>
                                                                  product.id,
                                                          ),
                                                )
                                            }
                                        />
                                    </th>
                                    <th className="px-5 py-3.5 text-start">
                                        {t('Product')}
                                    </th>
                                    <th className="px-5 py-3.5 text-start">
                                        {t('SKU')}
                                    </th>
                                    <th className="px-5 py-3.5 text-start">
                                        {t('Price')}
                                    </th>
                                    <th className="px-5 py-3.5 text-start">
                                        {t('Stock')}
                                    </th>
                                    <th className="px-5 py-3.5 text-start">
                                        {t('Status')}
                                    </th>
                                    <th className="px-5 py-3.5 text-end">
                                        {t('Actions')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {products.data.map((product) => (
                                    <tr
                                        key={product.id}
                                        ref={
                                            highlight === product.id
                                                ? savedRowRef
                                                : undefined
                                        }
                                        className={cn(
                                            'transition hover:bg-surface/60',
                                            selected.includes(product.id) &&
                                                'bg-brand/5',
                                            highlight === product.id &&
                                                savedRowClass,
                                        )}
                                    >
                                        <td
                                            data-cell="select"
                                            className="py-3 ps-5"
                                        >
                                            <input
                                                type="checkbox"
                                                aria-label={
                                                    localized(
                                                        product.translations,
                                                        locale,
                                                    ) ?? String(product.id)
                                                }
                                                checked={selected.includes(
                                                    product.id,
                                                )}
                                                onChange={() =>
                                                    setSelected((current) =>
                                                        current.includes(
                                                            product.id,
                                                        )
                                                            ? current.filter(
                                                                  (id) =>
                                                                      id !==
                                                                      product.id,
                                                              )
                                                            : [
                                                                  ...current,
                                                                  product.id,
                                                              ],
                                                    )
                                                }
                                            />
                                        </td>
                                        <td
                                            data-cell="primary"
                                            className="px-5 py-3"
                                        >
                                            <div className="flex items-center gap-3">
                                                {product.image_url ? (
                                                    <img
                                                        src={product.image_url}
                                                        alt=""
                                                        loading="lazy"
                                                        decoding="async"
                                                        className="h-12 w-12 shrink-0 rounded-lg bg-surface object-contain p-1"
                                                    />
                                                ) : (
                                                    <span className="h-12 w-12 shrink-0 rounded-lg bg-surface" />
                                                )}
                                                <div className="min-w-0">
                                                    <p className="flex items-center gap-1.5 truncate font-bold">
                                                        {product.is_featured && (
                                                            <Sparkles
                                                                size={14}
                                                                className="shrink-0 text-sky-600"
                                                                aria-label={t(
                                                                    'Featured on the home page',
                                                                )}
                                                            />
                                                        )}
                                                        <span className="truncate">
                                                            {localized(
                                                                product.translations,
                                                                locale,
                                                            )}
                                                        </span>
                                                    </p>
                                                    <p className="mt-0.5 text-xs text-ink-soft">
                                                        {localized(
                                                            product.category,
                                                            locale,
                                                        ) ?? t('Uncategorized')}
                                                    </p>
                                                </div>
                                            </div>
                                        </td>
                                        <td
                                            data-label={t('SKU')}
                                            className="px-5 py-3 text-ink-soft"
                                        >
                                            <span dir="ltr">
                                                {product.sku || '—'}
                                            </span>
                                        </td>
                                        <td
                                            data-label={t('Price')}
                                            className="px-5 py-3 font-semibold"
                                        >
                                            <span>
                                                {product.sale_price ? (
                                                    <>
                                                        <span
                                                            dir="ltr"
                                                            className="text-rose-700"
                                                        >
                                                            {formatMoney(
                                                                product.sale_price,
                                                                product.currency,
                                                                'en',
                                                            )}
                                                        </span>
                                                        <span className="mt-0.5 flex items-center gap-1.5 text-xs font-medium text-ink-soft">
                                                            <s dir="ltr">
                                                                {formatMoney(
                                                                    product.price,
                                                                    product.currency,
                                                                    'en',
                                                                )}
                                                            </s>
                                                            <span
                                                                dir="ltr"
                                                                className="rounded bg-rose-600 px-1 font-bold text-white"
                                                            >
                                                                -
                                                                {
                                                                    product.discount_percent
                                                                }
                                                                %
                                                            </span>
                                                        </span>
                                                        {product.offer && (
                                                            <span className="mt-0.5 block text-xs font-medium text-sky-800">
                                                                {product.offer}
                                                            </span>
                                                        )}
                                                    </>
                                                ) : (
                                                    <span dir="ltr">
                                                        {formatMoney(
                                                            product.price,
                                                            product.currency,
                                                            'en',
                                                        )}
                                                    </span>
                                                )}
                                                {product.price_is_estimate && (
                                                    <span className="mt-1 block text-xs font-medium text-amber-700">
                                                        {t('Approximate')}
                                                    </span>
                                                )}
                                            </span>
                                        </td>
                                        <td
                                            data-label={t('Stock')}
                                            className="px-5 py-3"
                                        >
                                            {product.stock_quantity}
                                        </td>
                                        <td
                                            data-label={t('Status')}
                                            className="px-5 py-3"
                                        >
                                            <VisibilityToggle
                                                visible={product.is_active}
                                                url={`/admin/products/${product.id}/visibility`}
                                            />
                                        </td>
                                        <td
                                            data-cell="actions"
                                            className="px-5 py-3 text-end whitespace-nowrap"
                                        >
                                            <Link
                                                href={withBack(
                                                    `/admin/products/${product.id}/edit`,
                                                )}
                                                className="font-bold text-brand-text hover:underline"
                                            >
                                                {t('Edit')}
                                            </Link>
                                            <span className="ms-4">
                                                <DeleteButton
                                                    url={`/admin/products/${product.id}`}
                                                    confirmText={t(
                                                        'Delete this product permanently? This cannot be undone. Past orders keep its name and price.',
                                                    )}
                                                />
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <EmptyState
                        icon={<Search aria-hidden="true" />}
                        title={t(
                            search
                                ? 'No results found.'
                                : 'Your catalog is ready',
                        )}
                        description={
                            search
                                ? undefined
                                : t(
                                      'Add your first product to start building the storefront.',
                                  )
                        }
                        action={
                            search ? undefined : (
                                <Link
                                    href={withBack('/admin/products/create')}
                                    className={primaryButton}
                                >
                                    {t('Add first product')}
                                </Link>
                            )
                        }
                    />
                )}
            </div>
            <Pagination links={products.links} />
            {selected.length > 0 && (
                <div aria-hidden="true" className="h-24 sm:hidden" />
            )}
        </StoreAdminLayout>
    );
}
