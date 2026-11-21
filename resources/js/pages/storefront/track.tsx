import { Head, useForm, usePage } from '@inertiajs/react';
import { PackageSearch } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { useTranslations } from '@/hooks/use-translations';
import Turnstile from '@/components/store/turnstile';
import StorefrontLayout from '@/layouts/storefront-layout';

export default function TrackOrder({
    reference,
    turnstileSiteKey,
}: {
    reference: string;
    turnstileSiteKey: string | null;
}) {
    const { locale } = usePage().props;
    const t = useTranslations();
    const [turnstileKey, setTurnstileKey] = useState(0);
    const form = useForm({ reference, phone: '', turnstile_token: '' });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(`/${locale}/track`, {
            // A token is good for one try only.
            onError: () => {
                if (turnstileSiteKey) {
                    form.setData('turnstile_token', '');
                    setTurnstileKey((key) => key + 1);
                }
            },
        });
    }

    return (
        <StorefrontLayout>
            <Head title={t('order_updates.track_title')} />
            <main className="mx-auto max-w-md px-4 py-10 sm:py-16">
                <form
                    onSubmit={submit}
                    className="rounded-panel border border-line bg-panel p-6 sm:p-8"
                >
                    <PackageSearch
                        size={44}
                        aria-hidden="true"
                        className="mx-auto text-brand-text"
                    />
                    <h1 className="mt-3 text-center text-2xl font-black">
                        {t('order_updates.track_title')}
                    </h1>
                    <p className="mt-2 text-center text-sm text-ink-soft">
                        {t('order_updates.track_intro')}
                    </p>

                    <label className="mt-6 grid gap-1.5 text-sm font-semibold">
                        {t('checkout.reference')}
                        <input
                            required
                            dir="ltr"
                            maxLength={12}
                            autoComplete="off"
                            value={form.data.reference}
                            onChange={(event) =>
                                form.setData(
                                    'reference',
                                    event.target.value.toUpperCase(),
                                )
                            }
                            className="admin-input tracking-widest uppercase"
                        />
                    </label>
                    <label className="mt-4 grid gap-1.5 text-sm font-semibold">
                        {t('checkout.customer_phone')}
                        <input
                            required
                            type="tel"
                            dir="ltr"
                            maxLength={25}
                            autoComplete="tel"
                            value={form.data.phone}
                            onChange={(event) =>
                                form.setData('phone', event.target.value)
                            }
                            className="admin-input"
                        />
                    </label>
                    {turnstileSiteKey && (
                        <div className="mt-4">
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
                    {(form.errors.reference ||
                        form.errors.phone ||
                        form.errors.turnstile_token) && (
                        <p
                            role="alert"
                            className="mt-3 text-sm font-semibold text-rose-700"
                        >
                            {form.errors.reference ||
                                form.errors.phone ||
                                form.errors.turnstile_token}
                        </p>
                    )}
                    <button
                        type="submit"
                        disabled={
                            form.processing ||
                            (!!turnstileSiteKey && !form.data.turnstile_token)
                        }
                        className="mt-6 h-12 w-full rounded-btn bg-brand font-bold text-on-brand transition hover:bg-brand-hover disabled:opacity-60"
                    >
                        {t('order_updates.track_submit')}
                    </button>
                </form>
            </main>
        </StorefrontLayout>
    );
}
