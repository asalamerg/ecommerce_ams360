import { Link, useForm, usePage } from '@inertiajs/react';
import {
    CheckCircle2,
    ImagePlus,
    MessageCircle,
    Wrench,
    X,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import SeoTitle from '@/components/store/seo-title';
import { RepairList } from '@/components/store/repair-list';
import type { RepairRequest } from '@/components/store/repair-list';
import Turnstile from '@/components/store/turnstile';
import { useTranslations } from '@/hooks/use-translations';
import StorefrontLayout from '@/layouts/storefront-layout';

/** A chosen photo before upload; its preview URL is freed when it goes. */
function PhotoPreview({ file }: { file: File }) {
    const [url, setUrl] = useState<string | null>(null);

    useEffect(() => {
        const objectUrl = URL.createObjectURL(file);
        setUrl(objectUrl);

        return () => URL.revokeObjectURL(objectUrl);
    }, [file]);

    return url ? (
        <img src={url} alt="" className="h-full w-full object-cover" />
    ) : null;
}

export default function Repairs({
    deviceTypes,
    branches,
    requests,
    submitted,
    turnstileSiteKey,
    maxPhotos,
}: {
    deviceTypes: string[];
    branches: { id: number; name: string }[];
    requests: RepairRequest[];
    submitted: number | null;
    turnstileSiteKey: string | null;
    maxPhotos: number;
}) {
    const { locale, auth, footer } = usePage().props;
    const t = useTranslations();
    const [turnstileKey, setTurnstileKey] = useState(0);
    const form = useForm<{
        device_type: string;
        device_model: string;
        issue: string;
        contact_phone: string;
        branch_id: string;
        photos: File[];
        turnstile_token: string;
    }>({
        device_type: deviceTypes[0] ?? 'phone',
        device_model: '',
        issue: '',
        contact_phone: '',
        branch_id: '',
        photos: [],
        turnstile_token: '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(`/${locale}/repairs`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => form.reset(),
            // A token is good for one try only.
            onFinish: () => {
                if (turnstileSiteKey) {
                    form.setData('turnstile_token', '');
                    setTurnstileKey((key) => key + 1);
                }
            },
        });
    }

    function addPhotos(files: FileList | null) {
        if (!files) {
            return;
        }

        form.setData(
            'photos',
            [...form.data.photos, ...Array.from(files)].slice(0, maxPhotos),
        );
    }

    const errors = form.errors as Record<string, string>;
    const error =
        errors.device_type ||
        errors.device_model ||
        errors.issue ||
        errors.contact_phone ||
        errors.branch_id ||
        errors.photos ||
        Object.entries(errors).find(([key]) =>
            key.startsWith('photos.'),
        )?.[1] ||
        errors.turnstile_token;

    return (
        <StorefrontLayout>
            <SeoTitle />
            <main className="mx-auto max-w-3xl px-4 py-8 sm:py-12">
                <Wrench
                    size={44}
                    aria-hidden="true"
                    className="text-brand-text"
                />
                <h1 className="mt-3 text-3xl font-black">
                    {t('service.repairs_title')}
                </h1>
                <p className="mt-2 text-ink-soft">
                    {t('service.repairs_intro')}
                </p>

                {submitted && (
                    <div
                        role="status"
                        className="mt-6 rounded-panel border border-emerald-200 bg-emerald-50 p-4 text-emerald-900"
                    >
                        <p className="flex items-center gap-2 font-bold">
                            <CheckCircle2 size={20} aria-hidden="true" />
                            {t('service.repair_sent', { id: submitted })}
                        </p>
                        {footer?.whatsapp && (
                            <a
                                href={`${footer.whatsapp}?text=${encodeURIComponent(t('service.repair_whatsapp_text', { id: submitted }))}`}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="mt-3 inline-flex items-center gap-2 rounded-full bg-[#25D366] px-4 py-2 text-sm font-bold text-[#0b3d1f]"
                            >
                                <MessageCircle size={16} aria-hidden="true" />
                                {t('service.repair_whatsapp')}
                            </a>
                        )}
                    </div>
                )}

                <form
                    onSubmit={submit}
                    className="mt-6 grid gap-4 rounded-panel border border-line bg-panel p-5 sm:grid-cols-2 sm:p-6"
                >
                    <label className="grid gap-1.5 text-sm font-semibold">
                        {t('service.device_type')}
                        <select
                            value={form.data.device_type}
                            onChange={(event) =>
                                form.setData('device_type', event.target.value)
                            }
                            className="admin-input"
                        >
                            {deviceTypes.map((type) => (
                                <option key={type} value={type}>
                                    {t(`service.device_${type}`)}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-1.5 text-sm font-semibold">
                        {t('service.device_model')}
                        <input
                            maxLength={120}
                            dir="auto"
                            placeholder={t('service.device_model_hint')}
                            value={form.data.device_model}
                            onChange={(event) =>
                                form.setData('device_model', event.target.value)
                            }
                            className="admin-input"
                        />
                    </label>
                    <label className="grid gap-1.5 text-sm font-semibold sm:col-span-2">
                        {t('service.issue')}
                        <textarea
                            required
                            rows={4}
                            minLength={5}
                            maxLength={2000}
                            value={form.data.issue}
                            onChange={(event) =>
                                form.setData('issue', event.target.value)
                            }
                            className="admin-input"
                        />
                    </label>
                    <label className="grid gap-1.5 text-sm font-semibold">
                        {t('service.phone')}
                        <input
                            required
                            type="tel"
                            dir="ltr"
                            maxLength={20}
                            autoComplete="tel"
                            value={form.data.contact_phone}
                            onChange={(event) =>
                                form.setData(
                                    'contact_phone',
                                    event.target.value,
                                )
                            }
                            className="admin-input"
                        />
                    </label>
                    {branches.length > 0 && (
                        <label className="grid gap-1.5 text-sm font-semibold">
                            {t('service.branch')}
                            <select
                                value={form.data.branch_id}
                                onChange={(event) =>
                                    form.setData(
                                        'branch_id',
                                        event.target.value,
                                    )
                                }
                                className="admin-input"
                            >
                                <option value="">
                                    {t('service.any_branch')}
                                </option>
                                {branches.map((branch) => (
                                    <option key={branch.id} value={branch.id}>
                                        {branch.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                    )}
                    <div className="grid gap-2 text-sm font-semibold sm:col-span-2">
                        {t('service.photos')}
                        <div className="flex flex-wrap gap-2">
                            {form.data.photos.map((photo, index) => (
                                <span
                                    key={`${photo.name}-${index}`}
                                    className="relative h-20 w-20 overflow-hidden rounded-lg border border-line"
                                >
                                    <PhotoPreview file={photo} />
                                    <button
                                        type="button"
                                        aria-label={t('store.remove')}
                                        onClick={() =>
                                            form.setData(
                                                'photos',
                                                form.data.photos.filter(
                                                    (_, other) =>
                                                        other !== index,
                                                ),
                                            )
                                        }
                                        className="absolute end-1 top-1 grid h-6 w-6 place-items-center rounded-full bg-black/60 text-white"
                                    >
                                        <X size={14} aria-hidden="true" />
                                    </button>
                                </span>
                            ))}
                            {form.data.photos.length < maxPhotos && (
                                <label className="grid h-20 w-20 cursor-pointer place-items-center rounded-lg border-2 border-dashed border-line text-ink-soft transition hover:border-brand hover:text-brand-text">
                                    <ImagePlus size={22} aria-hidden="true" />
                                    <input
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp"
                                        multiple
                                        className="sr-only"
                                        aria-label={t('service.photos')}
                                        onChange={(event) => {
                                            addPhotos(event.target.files);
                                            event.target.value = '';
                                        }}
                                    />
                                </label>
                            )}
                        </div>
                        <span className="text-xs font-normal text-ink-soft">
                            {t('service.photos_hint', { count: maxPhotos })}
                        </span>
                    </div>
                    {turnstileSiteKey && (
                        <div className="sm:col-span-2">
                            <Turnstile
                                key={turnstileKey}
                                siteKey={turnstileSiteKey}
                                locale={locale}
                                onToken={(token) =>
                                    form.setData('turnstile_token', token)
                                }
                            />
                        </div>
                    )}
                    {error && (
                        <p
                            role="alert"
                            className="text-sm font-semibold text-rose-700 sm:col-span-2"
                        >
                            {error}
                        </p>
                    )}
                    <button
                        type="submit"
                        disabled={
                            form.processing ||
                            (!!turnstileSiteKey && !form.data.turnstile_token)
                        }
                        className="h-12 rounded-btn bg-brand font-bold text-on-brand transition hover:bg-brand-hover disabled:opacity-60 sm:col-span-2"
                    >
                        {t(
                            form.processing
                                ? 'service.sending'
                                : 'service.submit_repair',
                        )}
                    </button>
                </form>

                {auth.user && requests.length > 0 && (
                    <section className="mt-8 rounded-panel border border-line bg-panel p-5">
                        <h2 className="text-lg font-black">
                            {t('service.my_repairs')}
                        </h2>
                        <RepairList requests={requests} />
                    </section>
                )}
                {!auth.user && (
                    <p className="mt-6 text-center text-sm text-ink-soft">
                        <Link
                            href="/login"
                            className="font-bold text-brand-text hover:underline"
                        >
                            {t('service.login_to_follow')}
                        </Link>
                    </p>
                )}
            </main>
        </StorefrontLayout>
    );
}
