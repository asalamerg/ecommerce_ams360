import { Link, useForm } from '@inertiajs/react';
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
import SearchableSelect from '@/components/admin/searchable-select';
import type { SelectOption } from '@/components/admin/searchable-select';
import SingleImageField from '@/components/admin/single-image-field';
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
type CatalogCategory = {
    id: number;
    is_active: boolean;
    show_on_home: boolean;
    position: number;
    parent_id: number | null;
    has_children: boolean;
    image_url: string | null;
    translations: Partial<Record<Locale, Partial<Translation>>>;
};
type FormData = {
    is_active: boolean;
    position: number;
    parent_id: string;
    translations: Record<Locale, Translation>;
    show_on_home: boolean;
    image: File | null;
    remove_image: boolean;
    back: string;
};
/** list: back to the list; new: an empty form for the next; stay: this form. */
type After = 'list' | 'new' | 'stay';

function initialTranslations(
    category: CatalogCategory | null,
): Record<Locale, Translation> {
    return Object.fromEntries(
        locales.map((locale) => {
            const translation = category?.translations[locale];
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

export default function CategoryForm({
    category,
    parents,
    back = '/admin/categories',
    defaults,
}: {
    category: CatalogCategory | null;
    parents: SelectOption[];
    back?: string;
    defaults?: { parent_id: number | null };
}) {
    const t = useAdminText();
    const [language, setLanguage] = useState<Locale>('ar');
    const title = t(category ? 'Edit category' : 'Create category');
    const form = useForm<FormData>({
        is_active: category?.is_active ?? true,
        position: category?.position ?? 0,
        parent_id:
            (category?.parent_id ?? defaults?.parent_id)?.toString() ?? '',
        show_on_home: category?.show_on_home ?? false,
        translations: initialTranslations(category),
        image: null,
        remove_image: false,
        back,
    });

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

        // Files need a multipart POST; Laravel reads _method as the real verb.
        form.transform((data) => ({
            ...data,
            after,
            ...(category ? { _method: 'put' } : {}),
        }));
        form.post(
            category ? `/admin/categories/${category.id}` : '/admin/categories',
            {
                forceFormData: true,
                preserveScroll: true,
                // Only "keep editing" lands back on this form with the category.
                onSuccess: (page) => {
                    const saved = page.props.category as
                        | CatalogCategory
                        | null
                        | undefined;

                    if (!saved) {
                        return;
                    }

                    form.setData((data) => ({
                        ...data,
                        translations: initialTranslations(saved),
                        image: null,
                        remove_image: false,
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
                {t('Categories')}
            </Link>
            <PageHeader title={title} />
            <form onSubmit={submit} className="space-y-6">
                <section className={`${panel} p-5 sm:p-6`}>
                    <h2 className="text-lg font-extrabold">
                        {t('Category settings')}
                    </h2>
                    <div className="mt-5 grid gap-5 sm:grid-cols-2">
                        <label className="grid gap-2 text-sm font-semibold">
                            {t('Display position')}
                            <input
                                type="number"
                                min="0"
                                max="65535"
                                step="1"
                                value={form.data.position}
                                onChange={(event) =>
                                    form.setData(
                                        'position',
                                        Number(event.target.value),
                                    )
                                }
                                required
                                className="admin-input"
                            />
                            {form.errors.position && (
                                <span className="text-xs text-rose-700">
                                    {form.errors.position}
                                </span>
                            )}
                        </label>
                        <div className="grid content-start gap-2 text-sm font-semibold">
                            {t('Main section')}
                            {category?.has_children ? (
                                <p className="text-xs font-normal text-ink-soft">
                                    {t(
                                        'This is a main section: other categories are under it.',
                                    )}
                                </p>
                            ) : (
                                <SearchableSelect
                                    value={form.data.parent_id}
                                    options={parents}
                                    onChange={(value) =>
                                        form.setData('parent_id', value)
                                    }
                                    emptyLabel={t(
                                        'None (this is a main section)',
                                    )}
                                />
                            )}
                            {form.errors.parent_id && (
                                <span className="text-xs text-rose-700">
                                    {form.errors.parent_id}
                                </span>
                            )}
                        </div>
                        <label className="mt-7 inline-flex items-center gap-2 text-sm font-semibold">
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
                            {t('Visible in the storefront')}
                        </label>
                        <label className="inline-flex items-start gap-2 text-sm font-semibold sm:col-span-2">
                            <input
                                type="checkbox"
                                checked={form.data.show_on_home}
                                onChange={(event) =>
                                    form.setData(
                                        'show_on_home',
                                        event.target.checked,
                                    )
                                }
                                className="admin-checkbox mt-0.5"
                            />
                            <span>
                                {t('Show as a section on the home page')}
                                <span className="mt-0.5 block text-xs font-normal text-ink-soft">
                                    {t(
                                        'The home page shows this category with its products, in the display position order. With no category chosen, the largest ones are shown.',
                                    )}
                                </span>
                            </span>
                        </label>
                        <SingleImageField
                            label={t('Category image')}
                            currentUrl={category?.image_url ?? null}
                            file={form.data.image}
                            removed={form.data.remove_image}
                            onFile={(file) =>
                                form.setData((data) => ({
                                    ...data,
                                    image: file,
                                    remove_image: file
                                        ? false
                                        : data.remove_image,
                                }))
                            }
                            onRemove={() => form.setData('remove_image', true)}
                            error={form.errors.image}
                            maxSide={800}
                        />
                    </div>
                </section>
                {[language].map((locale) => (
                    <section key={locale} className={`${panel} p-5 sm:p-6`}>
                        <LanguageTabs
                            active={language}
                            onChange={setLanguage}
                            withErrors={errorLocales}
                        />
                        <div className="mt-5 grid gap-5 sm:grid-cols-2">
                            <label className="grid gap-2 text-sm font-semibold">
                                {t('Category name')}
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
                                {t('URL slug')}
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
                                    rows={3}
                                    maxLength={20000}
                                    dir={locale === 'en' ? 'ltr' : 'auto'}
                                    className="admin-input"
                                />
                            </label>
                        </div>
                    </section>
                ))}
                {Object.entries(form.errors)
                    .filter(([key]) => !key.startsWith('translations.'))
                    .map(([key, error]) => (
                        <p key={key} className="text-sm text-rose-700">
                            {error}
                        </p>
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
                                : category
                                  ? 'Save changes'
                                  : 'Create category',
                        )}
                    </button>
                    <button
                        value={category ? 'stay' : 'new'}
                        disabled={form.processing}
                        className={secondaryButton}
                    >
                        {t(
                            category
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
