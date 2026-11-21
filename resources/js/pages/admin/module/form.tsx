import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft, ArrowRight } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import {
    FieldError,
    LanguageTabs,
    PageHeader,
    contentLocales,
    panel,
    primaryButton,
    secondaryButton,
} from '@/components/admin/admin-ui';
import type { ContentLocale } from '@/components/admin/admin-ui';
import SearchableSelect from '@/components/admin/searchable-select';
import type { SelectOption } from '@/components/admin/searchable-select';
import SingleImageField from '@/components/admin/single-image-field';
import {
    HoursField,
    ImagesField,
    ListField,
    RelationsField,
} from '@/components/admin/structured-fields';
import { useAdminText } from '@/hooks/use-admin-text';
import StoreAdminLayout from '@/layouts/store-admin-layout';

type Field = {
    name: string;
    label: string;
    type:
        | 'text'
        | 'url'
        | 'email'
        | 'number'
        | 'textarea'
        | 'select'
        | 'checkbox'
        | 'datetime-local'
        | 'image'
        | 'relation'
        | 'relations'
        | 'hours'
        | 'list'
        | 'images';
    options?: Record<string, string> | null;
    choices?: SelectOption[] | null;
    readOnly?: boolean;
    locale?: ContentLocale;
    max_side?: number;
    wide?: boolean;
};
type Module = {
    key: string;
    title: string;
    description: string;
    fields: Field[];
    canCreate: boolean;
    singleton: boolean;
};
/** list: back to the list; new: an empty form for the next; stay: this form. */
type After = 'list' | 'new' | 'stay';
type Values = Record<string, string | boolean | File | null>;
type RecordData = { id: number; values: Values } | null;
type RelatedItem = {
    name: string;
    price: string;
    quantity: number;
    color: string | null;
};

function ModuleField({
    field,
    value,
    error,
    onChange,
}: {
    field: Field;
    value: string | boolean;
    error?: string;
    onChange: (value: string | boolean) => void;
}) {
    const wide = field.type === 'textarea' || field.wide ? 'sm:col-span-2' : '';
    const text = String(value ?? '');

    if (['relations', 'hours', 'list', 'images'].includes(field.type)) {
        return (
            <div className={`grid content-start gap-2 text-sm ${wide}`}>
                <span className="font-semibold">{field.label}</span>
                {field.type === 'relations' && (
                    <RelationsField
                        value={text}
                        choices={field.choices ?? []}
                        onChange={onChange}
                    />
                )}
                {field.type === 'hours' && (
                    <HoursField value={text} onChange={onChange} />
                )}
                {field.type === 'list' && (
                    <ListField value={text} onChange={onChange} />
                )}
                {field.type === 'images' && <ImagesField value={text} />}
                <FieldError message={error} />
            </div>
        );
    }

    if (field.type === 'relation') {
        return (
            <div className={`grid content-start gap-2 text-sm ${wide}`}>
                <span className="font-semibold">{field.label}</span>
                <SearchableSelect
                    value={text}
                    options={field.choices ?? []}
                    onChange={onChange}
                    emptyLabel="—"
                />
                <FieldError message={error} />
            </div>
        );
    }

    if (field.type === 'checkbox') {
        return (
            <label className="flex items-center gap-3 self-end py-2 text-sm font-semibold sm:col-span-2">
                <input
                    type="checkbox"
                    checked={Boolean(value)}
                    disabled={field.readOnly}
                    onChange={(event) => onChange(event.target.checked)}
                    className="admin-checkbox"
                />
                {field.label}
                <FieldError message={error} />
            </label>
        );
    }

    return (
        <label
            className={`grid content-start gap-2 text-sm font-semibold ${wide}`}
        >
            {field.label}
            {field.type === 'textarea' ? (
                <textarea
                    value={String(value ?? '')}
                    onChange={(event) => onChange(event.target.value)}
                    rows={5}
                    readOnly={field.readOnly}
                    dir={field.locale === 'en' ? 'ltr' : undefined}
                    className="admin-input"
                />
            ) : field.type === 'select' ? (
                <select
                    value={String(value ?? '')}
                    disabled={field.readOnly}
                    onChange={(event) => onChange(event.target.value)}
                    className="admin-input"
                >
                    {Object.entries(field.options ?? {}).map(
                        ([option, label]) => (
                            <option key={option} value={option}>
                                {label}
                            </option>
                        ),
                    )}
                </select>
            ) : (
                <input
                    type={field.type}
                    value={String(value ?? '')}
                    onChange={(event) => onChange(event.target.value)}
                    step={field.type === 'number' ? 'any' : undefined}
                    readOnly={field.readOnly}
                    dir={
                        field.locale === 'en' ||
                        ['url', 'email'].includes(field.type)
                            ? 'ltr'
                            : undefined
                    }
                    className="admin-input"
                />
            )}
            <FieldError message={error} />
        </label>
    );
}

export default function StoreModuleForm({
    module,
    record,
    values,
    relatedItems,
    back,
}: {
    module: Module;
    record: RecordData;
    values: Values;
    relatedItems: RelatedItem[];
    /** The list page to go back to, see AdminReturnUrl. */
    back: string;
}) {
    const { direction, locale } = usePage().props;
    const t = useAdminText();
    const imageFields = module.fields.filter((field) => field.type === 'image');
    const form = useForm<Values>({
        ...values,
        ...Object.fromEntries(
            imageFields.map((field) => [`${field.name}_file`, null]),
        ),
    });
    // The saved URL of each image, kept until the save succeeds.
    const [savedImages, setSavedImages] = useState(() =>
        Object.fromEntries(
            imageFields.map((field) => [field.name, values[field.name]]),
        ),
    );
    const [language, setLanguage] = useState<ContentLocale>(
        contentLocales.includes(locale as ContentLocale)
            ? (locale as ContentLocale)
            : 'ar',
    );
    const BackIcon = direction === 'rtl' ? ArrowRight : ArrowLeft;
    const listUrl = `/admin/modules/${module.key}`;
    const title = t(record ? 'Manage :title' : 'New :title', {
        title: module.title,
    });

    const generalFields = module.fields.filter((field) => !field.locale);
    const localizedFields = module.fields.filter((field) => field.locale);
    const errorLocales = contentLocales.filter((code) =>
        localizedFields.some(
            (field) => field.locale === code && form.errors[field.name],
        ),
    );

    /**
     * After a save, show what the server now holds. Read-only values (a points
     * balance, points earned) change on save, and one-off fields such as a
     * points adjustment must empty, or saving again would apply them twice.
     */
    function showSaved(saved: Values) {
        setSavedImages(
            Object.fromEntries(
                imageFields.map((field) => [field.name, saved[field.name]]),
            ),
        );
        form.setData({
            ...saved,
            ...Object.fromEntries(
                imageFields.map((field) => [`${field.name}_file`, null]),
            ),
        });
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        // The button pressed says where to go next; Enter in a field means the main one.
        const submitter = (event.nativeEvent as SubmitEvent)
            .submitter as HTMLButtonElement | null;
        const after = (submitter?.value || 'list') as After;

        const options = {
            preserveScroll: true,
            // Only "keep editing" lands back on this form with the saved values.
            onSuccess: (page: {
                component: string;
                props: Record<string, unknown>;
            }) => {
                if (
                    page.props.values &&
                    page.component === 'admin/module/form'
                ) {
                    showSaved(page.props.values as Values);
                }
            },
        };

        // Files need a multipart POST; Laravel reads _method as the real verb.
        form.transform((data) => ({
            ...data,
            back,
            after,
            ...(record && imageFields.length > 0 ? { _method: 'put' } : {}),
        }));

        if (imageFields.length === 0) {
            if (record) {
                form.put(`${listUrl}/${record.id}`, options);
            } else {
                form.post(listUrl, options);
            }

            return;
        }

        form.post(record ? `${listUrl}/${record.id}` : listUrl, {
            ...options,
            forceFormData: true,
        });
    }

    function renderField(field: Field) {
        if (field.type === 'image') {
            const saved = savedImages[field.name];

            return (
                <SingleImageField
                    key={field.name}
                    label={field.label}
                    currentUrl={
                        typeof saved === 'string' && saved ? saved : null
                    }
                    file={
                        (form.data[`${field.name}_file`] as File | null) ?? null
                    }
                    removed={form.data[field.name] === ''}
                    onFile={(file) =>
                        form.setData((data) => ({
                            ...data,
                            [`${field.name}_file`]: file,
                            ...(file ? { [field.name]: saved ?? '' } : {}),
                        }))
                    }
                    onRemove={() => form.setData(field.name, '')}
                    error={
                        form.errors[`${field.name}_file`] ??
                        form.errors[field.name]
                    }
                    maxSide={field.max_side}
                    wide={field.wide}
                />
            );
        }

        return (
            <ModuleField
                key={field.name}
                field={field}
                value={form.data[field.name] as string | boolean}
                error={form.errors[field.name]}
                onChange={(value) => form.setData(field.name, value)}
            />
        );
    }

    return (
        <StoreAdminLayout title={title}>
            <Link
                href={back}
                className="mb-3 inline-flex items-center gap-1.5 text-sm font-bold text-brand-text hover:underline"
            >
                <BackIcon size={15} aria-hidden="true" />
                {module.title}
            </Link>
            <PageHeader title={title} description={module.description} />

            <form onSubmit={submit} className="space-y-5">
                {localizedFields.length > 0 && (
                    <section className={`${panel} p-5 sm:p-6`}>
                        <LanguageTabs
                            active={language}
                            onChange={setLanguage}
                            withErrors={errorLocales}
                        />
                        <div className="mt-5 grid gap-5 sm:grid-cols-2">
                            {localizedFields
                                .filter((field) => field.locale === language)
                                .map(renderField)}
                        </div>
                    </section>
                )}

                {generalFields.length > 0 && (
                    <section
                        className={`${panel} grid gap-5 p-5 sm:grid-cols-2 sm:p-6`}
                    >
                        {generalFields.map(renderField)}
                        {form.errors.module && (
                            <p className="text-sm text-rose-700 sm:col-span-2">
                                {form.errors.module}
                            </p>
                        )}
                    </section>
                )}

                {relatedItems.length > 0 && (
                    <section className={`${panel} overflow-hidden`}>
                        <h2 className="border-b border-line px-5 py-4 text-lg font-extrabold">
                            {t('Order items')}
                        </h2>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[480px] text-start text-sm">
                                <thead className="bg-surface text-xs text-ink-soft">
                                    <tr>
                                        <th className="px-5 py-3 text-start">
                                            {t('Item')}
                                        </th>
                                        <th className="px-5 py-3 text-start">
                                            {t('Color')}
                                        </th>
                                        <th className="px-5 py-3 text-start">
                                            {t('Qty')}
                                        </th>
                                        <th className="px-5 py-3 text-start">
                                            {t('Unit price')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-line">
                                    {relatedItems.map((item, index) => (
                                        <tr key={`${item.name}-${index}`}>
                                            <td className="px-5 py-3 font-semibold">
                                                {item.name}
                                            </td>
                                            <td className="px-5 py-3">
                                                {item.color || '—'}
                                            </td>
                                            <td className="px-5 py-3">
                                                {item.quantity}
                                            </td>
                                            <td className="px-5 py-3">
                                                {item.price}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                )}

                {/* Stays in reach while scrolling a long form. */}
                <div className="sticky bottom-(--admin-bottom) z-20 flex flex-wrap gap-3 rounded-panel border border-line bg-panel/95 p-3 shadow-lg backdrop-blur max-sm:flex-nowrap max-sm:gap-2 max-sm:p-2 max-sm:[&>*]:min-w-0 max-sm:[&>*]:flex-1 max-sm:[&>*]:px-2 max-sm:[&>*]:text-center max-sm:[&>*]:leading-tight">
                    <button
                        type="submit"
                        value={module.singleton ? 'stay' : 'list'}
                        disabled={form.processing}
                        className={primaryButton}
                    >
                        {t(form.processing ? 'Saving…' : 'Save changes')}
                    </button>
                    {!module.singleton && (record || module.canCreate) && (
                        <button
                            type="submit"
                            value={record ? 'stay' : 'new'}
                            disabled={form.processing}
                            className={secondaryButton}
                        >
                            {t(
                                record
                                    ? 'Save and keep editing'
                                    : 'Save and add another',
                            )}
                        </button>
                    )}
                    <Link href={back} className={secondaryButton}>
                        {t('Cancel')}
                    </Link>
                </div>
            </form>
        </StoreAdminLayout>
    );
}
