import { Link, useForm, usePage } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import {
    LanguageTabs,
    PageHeader,
    contentLocales as locales,
    panel,
    primaryButton,
    secondaryButton,
} from '@/components/admin/admin-ui';
import type { ContentLocale as Locale } from '@/components/admin/admin-ui';
import ProductColorsEditor from '@/components/admin/product-colors-editor';
import type { ColorRow } from '@/components/admin/product-colors-editor';
import ProductImages from '@/components/admin/product-images';
import type { ImageItem } from '@/components/admin/product-images';
import SearchableSelect from '@/components/admin/searchable-select';
import { useAdminText } from '@/hooks/use-admin-text';
import StoreAdminLayout from '@/layouts/store-admin-layout';
import { slugPreview } from '@/lib/slug';

type Translation = {
    name: string;
    slug: string;
    description: string;
    seo_title: string;
    seo_description: string;
};
type CatalogProduct = {
    id: number;
    category_id: number | null;
    brand_id: number | null;
    sku: string | null;
    aliases: string | null;
    price: string;
    discount_percent: number;
    discount_ends_at: string | null;
    /** An offer giving a lower price than the product's own discount. */
    running_offer: { title: string | null; sale_price: string } | null;
    currency: string;
    stock_quantity: number;
    is_active: boolean;
    is_featured: boolean;
    price_is_estimate: boolean;
    translations: Partial<Record<Locale, Partial<Translation>>>;
    images: { id: number; url: string }[];
    colors: {
        id: number;
        hex: string;
        name: Partial<Record<Locale, string>>;
    }[];
};
type CategoryOption = { id: number; names: Record<string, string> };
type BrandOption = { id: number; names: Record<string, string> };
type FormData = {
    category_id: string;
    brand_id: string;
    sku: string;
    aliases: string;
    price: string;
    discount_percent: string;
    discount_ends_at: string;
    currency: string;
    is_active: boolean;
    is_featured: boolean;
    price_is_estimate: boolean;
    translations: Record<Locale, Translation>;
    stock_quantity: string;
    stock_quantity_was: number;
    /** The list page to go back to, see CatalogController::listUrl(). */
    back: string;
};
/** list: back to the list; new: an empty form for the next; stay: this form. */
type After = 'list' | 'new' | 'stay';

/** Stock is an inventory permission, see UserRole::canManageInventory(). */
const STOCK_ROLES = ['owner', 'administrator', 'inventory_manager'];

function optionLabel(names: Record<string, string>): string {
    return names.ar ?? names.en ?? names.he ?? '';
}

function optionHint(names: Record<string, string>): string {
    return [names.en, names.he]
        .filter((name) => name && name !== optionLabel(names))
        .join(' · ');
}

function initialTranslations(
    product: CatalogProduct | null,
): Record<Locale, Translation> {
    return Object.fromEntries(
        locales.map((locale) => {
            const translation = product?.translations[locale];
            return [
                locale,
                {
                    name: translation?.name ?? '',
                    slug: translation?.slug ?? '',
                    description: translation?.description ?? '',
                    seo_title: translation?.seo_title ?? '',
                    seo_description: translation?.seo_description ?? '',
                },
            ];
        }),
    ) as Record<Locale, Translation>;
}

function savedColors(colors: CatalogProduct['colors'] = []): ColorRow[] {
    return colors.map((color) => ({
        key: `color-${color.id}`,
        id: color.id,
        hex: color.hex,
        name: {
            ar: color.name.ar ?? '',
            en: color.name.en ?? '',
            he: color.name.he ?? '',
        },
    }));
}

function savedImages(images: CatalogProduct['images'] = []): ImageItem[] {
    return images.map((image) => ({
        key: `existing-${image.id}`,
        kind: 'existing',
        ...image,
    }));
}

/** The same rounding as Product::salePrice(): down to the agora. */
function discountedPrice(price: string, percent: string): number | null {
    const amount = Number(price);
    const off = Number(percent);

    if (!(amount > 0) || !(off > 0) || off > 90) {
        return null;
    }

    return Math.floor(((amount * (100 - off)) / 100) * 100 + 1e-6) / 100;
}

export default function ProductForm({
    product,
    categories,
    brands,
    back = '/admin/products',
    defaults,
}: {
    product: CatalogProduct | null;
    categories: CategoryOption[];
    brands: BrandOption[];
    back?: string;
    defaults?: { category_id: number | null; brand_id: number | null };
}) {
    const { auth, locale } = usePage().props;
    const canStock = STOCK_ROLES.includes(auth.user?.role ?? '');
    const t = useAdminText();
    const [language, setLanguage] = useState<Locale>('ar');
    const title = t(product ? 'Edit product' : 'Create product');
    // The saved product as customers see it, in the admin's language when it has a link there.
    const storeLocale = (
        product?.translations[locale as Locale]?.slug ? locale : 'ar'
    ) as Locale;
    const storeSlug = product?.translations[storeLocale]?.slug;
    const storeUrl =
        product?.is_active && storeSlug
            ? `/${storeLocale}/products/${storeSlug}`
            : null;
    const [images, setImages] = useState<ImageItem[]>(
        savedImages(product?.images),
    );
    const [colors, setColors] = useState<ColorRow[]>(
        savedColors(product?.colors),
    );
    const form = useForm<FormData>({
        category_id:
            (product?.category_id ?? defaults?.category_id)?.toString() ?? '',
        brand_id: (product?.brand_id ?? defaults?.brand_id)?.toString() ?? '',
        sku: product?.sku ?? '',
        aliases: product?.aliases ?? '',
        price: product?.price ?? '',
        discount_percent: product?.discount_percent
            ? String(product.discount_percent)
            : '',
        discount_ends_at: product?.discount_ends_at ?? '',
        // The store sells in shekels; the field is there for the rare exception.
        currency: product?.currency ?? 'ILS',
        is_active: product?.is_active ?? true,
        is_featured: product?.is_featured ?? false,
        price_is_estimate: product?.price_is_estimate ?? false,
        translations: initialTranslations(product),
        stock_quantity: product ? String(product.stock_quantity) : '',
        stock_quantity_was: product?.stock_quantity ?? 0,
        back,
    });
    const salePrice = discountedPrice(
        form.data.price,
        form.data.discount_percent,
    );

    const errorLocales = locales.filter((locale) =>
        Object.keys(form.errors).some((key) =>
            key.startsWith(`translations.${locale}.`),
        ),
    );

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        // The button pressed says where to go next; Enter in a field means the main one.
        const submitter = (event.nativeEvent as SubmitEvent)
            .submitter as HTMLButtonElement | null;
        const after = (submitter?.value || 'list') as After;

        const uploads = images.flatMap((image) =>
            image.kind === 'new' ? [image.file] : [],
        );
        let index = 0;

        form.transform(({ stock_quantity, stock_quantity_was, ...data }) => ({
            ...data,
            after,
            ...(canStock && stock_quantity !== ''
                ? { stock_quantity, stock_quantity_was }
                : {}),
            ...(product ? { _method: 'put' } : {}),
            sync_images: true,
            sync_colors: true,
            colors: colors.map(({ id, hex, name }) => ({
                ...(id ? { id } : {}),
                hex,
                name,
            })),
            new_images: uploads,
            image_order: images.map((image) =>
                image.kind === 'new'
                    ? `new-${index++}`
                    : `existing-${image.id}`,
            ),
        }));
        form.post(
            product ? `/admin/products/${product.id}` : '/admin/products',
            {
                forceFormData: true,
                preserveScroll: true,
                // Saved uploads become existing images, so saving again cannot
                // repeat them. Generated slugs and the new stock show up too.
                onSuccess: (page) => {
                    const saved = page.props.product as
                        | CatalogProduct
                        | null
                        | undefined;

                    // Only "keep editing" lands back on this form with the product.
                    if (!saved) {
                        return;
                    }

                    setImages(savedImages(saved.images));
                    setColors(savedColors(saved.colors));
                    form.setData((data) => ({
                        ...data,
                        translations: initialTranslations(saved),
                        stock_quantity: String(saved.stock_quantity),
                        stock_quantity_was: saved.stock_quantity,
                    }));
                },
            },
        );
    }

    return (
        <StoreAdminLayout title={title}>
            <Link
                href={back}
                className="mb-3 inline-block text-sm font-bold text-brand-text hover:underline"
            >
                {t('Products')}
            </Link>
            <PageHeader
                title={title}
                description={t(
                    'Only the Arabic name is required; an empty English or Hebrew name shows the Arabic one. Links are made from the English name when there is one.',
                )}
            >
                {storeUrl && (
                    <a href={storeUrl} className={secondaryButton}>
                        <ExternalLink size={16} aria-hidden="true" />
                        {t('View in store')}
                    </a>
                )}
            </PageHeader>
            <form onSubmit={submit} className="space-y-6">
                <section className={`${panel} p-5 sm:p-6`}>
                    <h2 className="text-lg font-extrabold">
                        {t('Catalog details')}
                    </h2>
                    <div className="mt-5 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        <div className="grid gap-2 text-sm font-semibold">
                            {t('Category')}
                            <SearchableSelect
                                value={form.data.category_id}
                                onChange={(value) =>
                                    form.setData('category_id', value)
                                }
                                emptyLabel={t('Uncategorized')}
                                placeholder={t('Search categories…')}
                                options={categories.map((category) => ({
                                    value: String(category.id),
                                    label: optionLabel(category.names),
                                    hint: optionHint(category.names),
                                }))}
                            />
                            {form.errors.category_id && (
                                <span className="text-xs text-rose-700">
                                    {form.errors.category_id}
                                </span>
                            )}
                        </div>
                        <div className="grid gap-2 text-sm font-semibold">
                            {t('Brand')}
                            <SearchableSelect
                                value={form.data.brand_id}
                                onChange={(value) =>
                                    form.setData('brand_id', value)
                                }
                                emptyLabel={t('No brand')}
                                placeholder={t('Search brands…')}
                                options={brands.map((brand) => ({
                                    value: String(brand.id),
                                    label: optionLabel(brand.names),
                                    hint: optionHint(brand.names),
                                }))}
                            />
                            {form.errors.brand_id && (
                                <span className="text-xs text-rose-700">
                                    {form.errors.brand_id}
                                </span>
                            )}
                        </div>
                        <label className="grid gap-2 text-sm font-semibold">
                            {t('SKU')}
                            <input
                                value={form.data.sku}
                                onChange={(event) =>
                                    form.setData('sku', event.target.value)
                                }
                                maxLength={100}
                                className="admin-input"
                            />
                            {form.errors.sku && (
                                <span className="text-xs text-rose-700">
                                    {form.errors.sku}
                                </span>
                            )}
                        </label>
                        <label className="grid gap-2 text-sm font-semibold sm:col-span-2">
                            {t('Other spellings for search')}
                            <input
                                value={form.data.aliases}
                                onChange={(event) =>
                                    form.setData('aliases', event.target.value)
                                }
                                maxLength={1000}
                                placeholder={t(
                                    'For example: ايفون، آيفون، iphone',
                                )}
                                className="admin-input"
                            />
                            <span className="text-xs font-normal text-ink-soft">
                                {t(
                                    'Separate with commas. Customers find the product by these words; they are never shown.',
                                )}
                            </span>
                        </label>
                        <label className="grid gap-2 text-sm font-semibold">
                            {t('Currency (ISO 4217)')}
                            <input
                                value={form.data.currency}
                                onChange={(event) =>
                                    form.setData(
                                        'currency',
                                        event.target.value.toUpperCase(),
                                    )
                                }
                                maxLength={3}
                                minLength={3}
                                placeholder="ILS"
                                className="admin-input uppercase"
                            />
                            {form.errors.currency && (
                                <span className="text-xs text-rose-700">
                                    {form.errors.currency}
                                </span>
                            )}
                        </label>
                        <label className="grid gap-2 text-sm font-semibold">
                            {t('Price')}
                            <input
                                type="number"
                                min="0"
                                step="0.0001"
                                value={form.data.price}
                                onChange={(event) =>
                                    form.setData('price', event.target.value)
                                }
                                required
                                className="admin-input"
                            />
                            {form.errors.price && (
                                <span className="text-xs text-rose-700">
                                    {form.errors.price}
                                </span>
                            )}
                        </label>
                        {canStock ? (
                            <label className="grid content-start gap-2 text-sm font-semibold">
                                {t('Quantity in stock')}
                                <input
                                    type="number"
                                    min="0"
                                    step="1"
                                    inputMode="numeric"
                                    value={form.data.stock_quantity}
                                    onChange={(event) =>
                                        form.setData(
                                            'stock_quantity',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="0"
                                    className="admin-input"
                                />
                                <span className="text-xs font-normal text-ink-soft">
                                    {t(
                                        'Every change is recorded in the stock movements log.',
                                    )}
                                    {product && (
                                        <>
                                            {' '}
                                            <Link
                                                href="/admin/inventory"
                                                className="font-bold text-brand-text hover:underline"
                                            >
                                                {t('Manage inventory')}
                                            </Link>
                                        </>
                                    )}
                                </span>
                                {form.errors.stock_quantity && (
                                    <span className="text-xs text-rose-700">
                                        {form.errors.stock_quantity}
                                    </span>
                                )}
                            </label>
                        ) : (
                            product && (
                                <div className="grid gap-2 text-sm font-semibold">
                                    {t('Available stock')}
                                    <p className="rounded-xl border border-line bg-surface px-3 py-2.5 text-ink/80">
                                        {product.stock_quantity}
                                    </p>
                                </div>
                            )
                        )}
                    </div>
                    <div className="mt-5 flex flex-wrap gap-6">
                        <label className="inline-flex items-center gap-2 text-sm font-semibold">
                            <input
                                type="checkbox"
                                checked={form.data.price_is_estimate}
                                onChange={(event) =>
                                    form.setData(
                                        'price_is_estimate',
                                        event.target.checked,
                                    )
                                }
                                className="admin-checkbox"
                            />
                            {t('Price is an estimate')}
                        </label>
                        <label className="inline-flex items-center gap-2 text-sm font-semibold">
                            <input
                                type="checkbox"
                                checked={form.data.is_active}
                                onChange={(event) =>
                                    form.setData(
                                        'is_active',
                                        event.target.checked,
                                    )
                                }
                                className="admin-checkbox"
                            />
                            {t('Shown in the store')}
                        </label>
                        <label className="inline-flex items-center gap-2 text-sm font-semibold">
                            <input
                                type="checkbox"
                                checked={form.data.is_featured}
                                onChange={(event) =>
                                    form.setData(
                                        'is_featured',
                                        event.target.checked,
                                    )
                                }
                                className="admin-checkbox"
                            />
                            {t('Feature on homepage')}
                        </label>
                    </div>
                    <fieldset className="mt-6 rounded-xl border border-line bg-surface/60 p-4">
                        <legend className="px-1 text-sm font-extrabold">
                            {t('Discount')}
                        </legend>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <label className="grid content-start gap-2 text-sm font-semibold">
                                {t('Discount (%)')}
                                <input
                                    type="number"
                                    min="0"
                                    max="90"
                                    step="1"
                                    inputMode="numeric"
                                    value={form.data.discount_percent}
                                    onChange={(event) =>
                                        form.setData(
                                            'discount_percent',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="0"
                                    className="admin-input"
                                />
                                <span className="text-xs font-normal text-ink-soft">
                                    {t(
                                        'Leave empty or 0 for no discount. Up to 90%.',
                                    )}
                                </span>
                                {form.errors.discount_percent && (
                                    <span className="text-xs text-rose-700">
                                        {form.errors.discount_percent}
                                    </span>
                                )}
                            </label>
                            <label className="grid content-start gap-2 text-sm font-semibold">
                                {t('Discount ends on')}
                                <input
                                    type="date"
                                    value={form.data.discount_ends_at}
                                    min={new Date().toISOString().slice(0, 10)}
                                    disabled={salePrice === null}
                                    onChange={(event) =>
                                        form.setData(
                                            'discount_ends_at',
                                            event.target.value,
                                        )
                                    }
                                    className="admin-input disabled:opacity-50"
                                />
                                <span className="text-xs font-normal text-ink-soft">
                                    {t(
                                        'Optional. Leave empty to keep the discount until you remove it.',
                                    )}
                                </span>
                                {form.errors.discount_ends_at && (
                                    <span className="text-xs text-rose-700">
                                        {form.errors.discount_ends_at}
                                    </span>
                                )}
                            </label>
                            <div className="grid content-start gap-2 text-sm font-semibold">
                                {t('Price after discount')}
                                <p
                                    className="flex min-h-11 items-center gap-2 rounded-xl border border-line bg-panel px-3 py-2"
                                    dir="ltr"
                                    aria-live="polite"
                                >
                                    {salePrice !== null ? (
                                        <>
                                            <span className="text-lg font-black text-rose-600">
                                                {salePrice.toFixed(2)}{' '}
                                                {form.data.currency}
                                            </span>
                                            <s className="text-ink-soft">
                                                {Number(
                                                    form.data.price,
                                                ).toFixed(2)}
                                            </s>
                                            <span className="ms-auto rounded-full bg-rose-600 px-2 py-0.5 text-xs font-bold text-white">
                                                -
                                                {Number(
                                                    form.data.discount_percent,
                                                )}
                                                %
                                            </span>
                                        </>
                                    ) : (
                                        <span className="text-ink-soft">—</span>
                                    )}
                                </p>
                            </div>
                        </div>
                        {product?.running_offer && (
                            <p className="mt-3 rounded-lg bg-sky-50 px-3 py-2 text-sm text-sky-950">
                                {t(
                                    'The offer ":offer" sells this product at :price now, which is lower than this discount.',
                                    {
                                        offer:
                                            product.running_offer.title ?? '',
                                        price: `${Number(product.running_offer.sale_price).toFixed(2)} ${product.currency}`,
                                    },
                                )}
                            </p>
                        )}
                    </fieldset>
                    {!form.data.is_active && (
                        <p className="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-950">
                            {t(
                                'This product is hidden: it does not appear in the store or its search until you publish it.',
                            )}
                        </p>
                    )}
                    {Object.entries(form.errors)
                        .filter(
                            ([key]) =>
                                !key.startsWith('translations.') &&
                                key !== 'stock_quantity' &&
                                !key.startsWith('new_images') &&
                                !key.startsWith('colors.') &&
                                !key.startsWith('image_order'),
                        )
                        .map(([key, error]) => (
                            <p key={key} className="mt-2 text-sm text-rose-700">
                                {error}
                            </p>
                        ))}
                </section>

                <ProductImages
                    items={images}
                    onChange={setImages}
                    errors={Object.entries(
                        form.errors as Record<string, string>,
                    )
                        .filter(
                            ([key]) =>
                                key.startsWith('new_images') ||
                                key.startsWith('image_order'),
                        )
                        .map(([, error]) => error)}
                />

                <ProductColorsEditor
                    rows={colors}
                    onChange={setColors}
                    errors={form.errors as Record<string, string>}
                />

                {[language].map((locale) => (
                    <section key={locale} className={`${panel} p-5 sm:p-6`}>
                        <LanguageTabs
                            active={language}
                            onChange={setLanguage}
                            withErrors={errorLocales}
                        />
                        <div className="mt-5 grid gap-5 sm:grid-cols-2">
                            <label className="grid gap-2 text-sm font-semibold">
                                {t('Product name')}
                                <input
                                    value={form.data.translations[locale].name}
                                    onChange={(event) =>
                                        form.setData('translations', {
                                            ...form.data.translations,
                                            [locale]: {
                                                ...form.data.translations[
                                                    locale
                                                ],
                                                name: event.target.value,
                                            },
                                        })
                                    }
                                    required
                                    maxLength={255}
                                    dir={locale === 'en' ? 'ltr' : 'auto'}
                                    className="admin-input"
                                />
                                {form.errors[`translations.${locale}.name`] && (
                                    <span className="text-xs text-rose-700">
                                        {
                                            form.errors[
                                                `translations.${locale}.name`
                                            ]
                                        }
                                    </span>
                                )}
                            </label>
                            <label className="grid gap-2 text-sm font-semibold">
                                {t(
                                    'URL slug (lowercase English letters and numbers)',
                                )}
                                <input
                                    value={form.data.translations[locale].slug}
                                    placeholder={
                                        slugPreview(form.data.translations) ||
                                        t('Made from the name automatically')
                                    }
                                    onChange={(event) =>
                                        form.setData('translations', {
                                            ...form.data.translations,
                                            [locale]: {
                                                ...form.data.translations[
                                                    locale
                                                ],
                                                slug: event.target.value.toLowerCase(),
                                            },
                                        })
                                    }
                                    maxLength={180}
                                    pattern="[a-z0-9]+(?:-[a-z0-9]+)*"
                                    dir="ltr"
                                    className="admin-input"
                                />
                                <span className="text-xs font-normal text-ink-soft">
                                    {t(
                                        'Leave it empty and it is made from the name.',
                                    )}
                                </span>
                                {form.errors[`translations.${locale}.slug`] && (
                                    <span className="text-xs text-rose-700">
                                        {
                                            form.errors[
                                                `translations.${locale}.slug`
                                            ]
                                        }
                                    </span>
                                )}
                            </label>
                            <label className="grid gap-2 text-sm font-semibold sm:col-span-2">
                                {t('Description')}
                                <textarea
                                    value={
                                        form.data.translations[locale]
                                            .description
                                    }
                                    onChange={(event) =>
                                        form.setData('translations', {
                                            ...form.data.translations,
                                            [locale]: {
                                                ...form.data.translations[
                                                    locale
                                                ],
                                                description: event.target.value,
                                            },
                                        })
                                    }
                                    rows={5}
                                    maxLength={20000}
                                    dir={locale === 'en' ? 'ltr' : 'auto'}
                                    className="admin-input"
                                />
                                {form.errors[
                                    `translations.${locale}.description`
                                ] && (
                                    <span className="text-xs text-rose-700">
                                        {
                                            form.errors[
                                                `translations.${locale}.description`
                                            ]
                                        }
                                    </span>
                                )}
                            </label>
                            <label className="grid gap-2 text-sm font-semibold">
                                {t('SEO title')}
                                <input
                                    value={
                                        form.data.translations[locale].seo_title
                                    }
                                    onChange={(event) =>
                                        form.setData('translations', {
                                            ...form.data.translations,
                                            [locale]: {
                                                ...form.data.translations[
                                                    locale
                                                ],
                                                seo_title: event.target.value,
                                            },
                                        })
                                    }
                                    maxLength={255}
                                    className="admin-input"
                                />
                            </label>
                            <label className="grid gap-2 text-sm font-semibold">
                                {t('SEO description')}
                                <textarea
                                    value={
                                        form.data.translations[locale]
                                            .seo_description
                                    }
                                    onChange={(event) =>
                                        form.setData('translations', {
                                            ...form.data.translations,
                                            [locale]: {
                                                ...form.data.translations[
                                                    locale
                                                ],
                                                seo_description:
                                                    event.target.value,
                                            },
                                        })
                                    }
                                    rows={2}
                                    maxLength={320}
                                    className="admin-input"
                                />
                            </label>
                        </div>
                    </section>
                ))}

                {/* Stays in reach while scrolling a long form. */}
                <div className="sticky bottom-(--admin-bottom) z-20 flex flex-wrap gap-3 rounded-panel border border-line bg-panel/95 p-3 shadow-lg backdrop-blur max-sm:flex-nowrap max-sm:gap-2 max-sm:p-2 max-sm:[&>*]:min-w-0 max-sm:[&>*]:flex-1 max-sm:[&>*]:px-2 max-sm:[&>*]:text-center max-sm:[&>*]:leading-tight">
                    <button
                        value="list"
                        disabled={form.processing}
                        className={primaryButton}
                    >
                        {t(
                            form.processing
                                ? 'Saving…'
                                : product
                                  ? 'Save changes'
                                  : 'Create product',
                        )}
                    </button>
                    <button
                        value={product ? 'stay' : 'new'}
                        disabled={form.processing}
                        className={secondaryButton}
                    >
                        {t(
                            product
                                ? 'Save and keep editing'
                                : 'Save and add another',
                        )}
                    </button>
                    <Link href={back} className={secondaryButton}>
                        {t('Cancel')}
                    </Link>
                </div>
            </form>
        </StoreAdminLayout>
    );
}
