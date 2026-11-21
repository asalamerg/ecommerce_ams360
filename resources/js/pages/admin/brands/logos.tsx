import { Link, router } from '@inertiajs/react';
import { ImageUp, Loader2, Trash2 } from 'lucide-react';
import type { DragEvent } from 'react';
import { useRef, useState } from 'react';
import {
    PageHeader,
    panel,
    secondaryButton,
} from '@/components/admin/admin-ui';
import { useAdminText } from '@/hooks/use-admin-text';
import StoreAdminLayout from '@/layouts/store-admin-layout';
import { compressImage } from '@/lib/compress-image';
import { cn } from '@/lib/utils';

type Brand = {
    id: number;
    name: string;
    slug: string;
    logo_url: string | null;
    products: number;
    is_active: boolean;
};

const ACCEPT = ['image/png', 'image/webp', 'image/jpeg'];

/**
 * All brand logos at once. Drop a file on a card, or press it to choose one;
 * it is saved straight away. Brands without a logo come first.
 */
export default function BrandLogos({
    brands,
    withLogo,
}: {
    brands: Brand[];
    withLogo: number;
}) {
    const t = useAdminText();
    const [filter, setFilter] = useState<'missing' | 'all'>(
        withLogo < brands.length ? 'missing' : 'all',
    );
    const shown =
        filter === 'missing'
            ? brands.filter((brand) => !brand.logo_url)
            : brands;
    const percent = brands.length
        ? Math.round((withLogo / brands.length) * 100)
        : 100;

    return (
        <StoreAdminLayout title={t('Brand logos')}>
            <PageHeader
                eyebrow={t('Catalog')}
                title={t('Brand logos')}
                description={t(
                    'Drop a logo on a brand, or press the brand to choose one. A transparent PNG or a logo on white looks best; the empty margin around it is cut away automatically.',
                )}
            />

            <section className={`${panel} mb-5 p-5`}>
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-sm font-bold">
                        {t(':done of :total brands have a logo', {
                            done: withLogo,
                            total: brands.length,
                        })}
                    </p>
                    <div className="flex gap-2">
                        {(['missing', 'all'] as const).map((key) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() => setFilter(key)}
                                className={cn(
                                    'rounded-full border px-3.5 py-1.5 text-sm font-bold transition',
                                    filter === key
                                        ? 'border-brand bg-brand text-on-brand'
                                        : 'border-line bg-panel hover:border-brand',
                                )}
                            >
                                {key === 'missing'
                                    ? t('Missing a logo (:count)', {
                                          count: brands.length - withLogo,
                                      })
                                    : t('All brands (:count)', {
                                          count: brands.length,
                                      })}
                            </button>
                        ))}
                    </div>
                </div>
                <div
                    className="mt-3 h-2 overflow-hidden rounded-full bg-surface"
                    role="progressbar"
                    aria-valuemin={0}
                    aria-valuemax={100}
                    aria-valuenow={percent}
                    aria-label={t('Brands with a logo')}
                >
                    <div
                        className="h-full rounded-full bg-emerald-500 transition-[width] duration-500"
                        style={{ width: `${percent}%` }}
                    />
                </div>
            </section>

            {shown.length ? (
                <ul className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                    {shown.map((brand) => (
                        <li key={brand.id}>
                            <LogoCard brand={brand} />
                        </li>
                    ))}
                </ul>
            ) : (
                <p
                    className={`${panel} p-10 text-center text-sm text-ink-soft`}
                >
                    {t('Every brand has a logo.')}
                </p>
            )}
        </StoreAdminLayout>
    );
}

function LogoCard({ brand }: { brand: Brand }) {
    const t = useAdminText();
    const input = useRef<HTMLInputElement>(null);
    const [dragging, setDragging] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [preview, setPreview] = useState<string | null>(null);
    const picture = preview ?? brand.logo_url;

    async function upload(file: File | undefined) {
        if (!file) {
            return;
        }

        if (!ACCEPT.includes(file.type)) {
            setError(t('Use a PNG, WebP or JPEG image.'));

            return;
        }

        setError(null);
        setBusy(true);
        setPreview(URL.createObjectURL(file));
        router.post(
            `/admin/brands/${brand.id}/logo`,
            { logo: await compressImage(file, 600) },
            {
                forceFormData: true,
                preserveScroll: true,
                // The card may move to "has a logo"; keep the filter as it is.
                preserveState: true,
                onError: (errors) => {
                    setPreview(null);
                    setError(errors.logo ?? t('The logo could not be saved.'));
                },
                onFinish: () => setBusy(false),
            },
        );
    }

    function drop(event: DragEvent<HTMLButtonElement>) {
        event.preventDefault();
        setDragging(false);
        void upload(event.dataTransfer.files[0]);
    }

    return (
        <div className={`${panel} flex h-full flex-col overflow-hidden`}>
            <button
                type="button"
                onClick={() => input.current?.click()}
                onDragOver={(event) => {
                    event.preventDefault();
                    setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={drop}
                aria-label={t('Upload a logo for :brand', {
                    brand: brand.name,
                })}
                className={cn(
                    'relative grid aspect-[4/3] place-items-center border-b border-line transition',
                    picture ? 'bg-white' : 'bg-surface',
                    dragging &&
                        'outline-2 outline-offset-[-6px] outline-brand outline-dashed',
                )}
            >
                {picture ? (
                    <img
                        src={picture}
                        alt=""
                        className="max-h-[70%] max-w-[80%] object-contain"
                    />
                ) : (
                    <span className="grid place-items-center gap-1 px-3 text-center text-ink-soft">
                        <ImageUp size={26} aria-hidden="true" />
                        <span className="text-xs font-semibold">
                            {t('Drop the logo here')}
                        </span>
                    </span>
                )}
                {busy && (
                    <span className="absolute inset-0 grid place-items-center bg-panel/70">
                        <Loader2
                            size={24}
                            aria-hidden="true"
                            className="animate-spin text-brand-text"
                        />
                    </span>
                )}
            </button>
            <input
                ref={input}
                type="file"
                accept={ACCEPT.join(',')}
                className="hidden"
                onChange={(event) => {
                    void upload(event.target.files?.[0]);
                    event.target.value = '';
                }}
            />
            <div className="flex flex-1 flex-col gap-2 p-3">
                <div className="min-w-0">
                    <p className="truncate font-bold">{brand.name}</p>
                    <p className="text-xs text-ink-soft">
                        {t(':count products', { count: brand.products })}
                        {!brand.is_active && ` · ${t('Hidden')}`}
                    </p>
                </div>
                {error && <p className="text-xs text-rose-700">{error}</p>}
                <div className="mt-auto flex items-center justify-between gap-2">
                    <Link
                        href={`/admin/modules/brands/${brand.id}/edit`}
                        className="text-xs font-bold text-brand-text hover:underline"
                    >
                        {t('Edit brand')}
                    </Link>
                    {brand.logo_url && (
                        <button
                            type="button"
                            onClick={() => {
                                if (window.confirm(t('Remove this logo?'))) {
                                    router.delete(
                                        `/admin/brands/${brand.id}/logo`,
                                        {
                                            preserveScroll: true,
                                            preserveState: true,
                                        },
                                    );
                                }
                            }}
                            className={cn(
                                secondaryButton,
                                'px-2.5 py-1.5 text-xs',
                            )}
                        >
                            <Trash2 size={13} aria-hidden="true" />
                            {t('Remove')}
                        </button>
                    )}
                </div>
            </div>
        </div>
    );
}
