import { useForm, usePage } from '@inertiajs/react';
import {
    Clock,
    Mail,
    MapPin,
    MessageCircle,
    Navigation,
    Phone,
    Truck,
    Undo2,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import SeoTitle from '@/components/store/seo-title';
import Turnstile from '@/components/store/turnstile';
import { useTranslations } from '@/hooks/use-translations';
import StorefrontLayout from '@/layouts/storefront-layout';
import type { FooterData } from '@/types';

type Hours = Record<string, { closed: boolean; open?: string; close?: string }>;
type Branch = {
    id: number;
    name: string;
    address: string | null;
    phone: string | null;
    allows_pickup: boolean;
    hours: Hours | null;
    map_url: string | null;
};

const DAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

function OpeningHours({ hours }: { hours: Hours }) {
    const t = useTranslations();
    const today = DAYS[new Date().getDay()];

    return (
        <dl className="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
            {DAYS.filter((day) => hours[day]).map((day) => (
                <div key={day} className="contents">
                    <dt
                        className={
                            day === today
                                ? 'font-bold text-ink'
                                : 'text-ink-soft'
                        }
                    >
                        {t(`service.day_${day}`)}
                    </dt>
                    <dd
                        dir="ltr"
                        className={
                            day === today
                                ? 'text-end font-bold text-ink rtl:text-start'
                                : 'text-end text-ink-soft rtl:text-start'
                        }
                    >
                        {hours[day].closed
                            ? t('service.closed')
                            : `${hours[day].open} – ${hours[day].close}`}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

export default function Contact({
    contact,
    branches,
    policies,
    turnstileSiteKey,
}: {
    contact: FooterData;
    branches: Branch[];
    policies: { delivery: string | null; returns: string | null };
    turnstileSiteKey: string | null;
}) {
    const { locale, flash } = usePage().props;
    const t = useTranslations();
    const [turnstileKey, setTurnstileKey] = useState(0);
    const form = useForm({
        name: '',
        phone: '',
        email: '',
        message: '',
        turnstile_token: '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(`/${locale}/contact`, {
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

    const error =
        form.errors.name ||
        form.errors.phone ||
        form.errors.email ||
        form.errors.message ||
        form.errors.turnstile_token;

    return (
        <StorefrontLayout>
            <SeoTitle />
            <main className="mx-auto max-w-6xl px-4 py-8 sm:px-6 sm:py-12 lg:px-8">
                <h1 className="text-3xl font-black sm:text-4xl">
                    {t('service.contact_title')}
                </h1>
                <p className="mt-2 max-w-2xl text-ink-soft">
                    {t('service.contact_intro')}
                </p>

                <div className="mt-8 grid gap-6 lg:grid-cols-[1fr_1.1fr]">
                    <div className="space-y-4">
                        {(contact.phone || contact.whatsapp) && (
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-1">
                                {contact.phone && (
                                    <a
                                        href={`tel:${contact.phone_link ?? contact.phone}`}
                                        className="flex items-center gap-3 rounded-panel border border-line bg-panel p-4 transition hover:border-brand"
                                    >
                                        <span className="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-brand/10 text-brand-text">
                                            <Phone
                                                size={20}
                                                aria-hidden="true"
                                            />
                                        </span>
                                        <span>
                                            <span className="block text-xs font-semibold text-ink-soft">
                                                {t('store.call_us')}
                                            </span>
                                            <span
                                                dir="ltr"
                                                className="font-black"
                                            >
                                                {contact.phone}
                                            </span>
                                        </span>
                                    </a>
                                )}
                                {contact.whatsapp && (
                                    <a
                                        href={`${contact.whatsapp}?text=${encodeURIComponent(t('store.whatsapp_hello'))}`}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="flex items-center gap-3 rounded-panel bg-[#25D366] p-4 font-black text-[#0b3d1f] transition hover:bg-[#1ebe5b]"
                                    >
                                        <span className="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-white/40">
                                            <MessageCircle
                                                size={20}
                                                aria-hidden="true"
                                            />
                                        </span>
                                        {t('store.whatsapp_chat')}
                                    </a>
                                )}
                            </div>
                        )}

                        {contact.address && (
                            <p className="flex items-start gap-3 rounded-panel border border-line bg-panel p-4 text-sm">
                                <MapPin
                                    size={18}
                                    aria-hidden="true"
                                    className="mt-0.5 shrink-0 text-brand-text"
                                />
                                {contact.address}
                            </p>
                        )}

                        {branches.length > 0 && (
                            <section aria-labelledby="branches">
                                <h2
                                    id="branches"
                                    className="mt-6 text-xl font-black"
                                >
                                    {t('service.branches')}
                                </h2>
                                <div className="mt-3 grid gap-3">
                                    {branches.map((branch) => (
                                        <article
                                            key={branch.id}
                                            className="rounded-panel border border-line bg-panel p-4"
                                        >
                                            <h3 className="font-black">
                                                {branch.name}
                                            </h3>
                                            {branch.address && (
                                                <p className="mt-1 text-sm whitespace-pre-line text-ink-soft">
                                                    {branch.address}
                                                </p>
                                            )}
                                            <div className="mt-2 flex flex-wrap gap-x-4 gap-y-2 text-sm font-bold">
                                                {branch.phone && (
                                                    <a
                                                        href={`tel:${branch.phone.replace(/[^\d+]/g, '')}`}
                                                        className="inline-flex items-center gap-1.5 text-brand-text hover:underline"
                                                    >
                                                        <Phone
                                                            size={15}
                                                            aria-hidden="true"
                                                        />
                                                        <span dir="ltr">
                                                            {branch.phone}
                                                        </span>
                                                    </a>
                                                )}
                                                {branch.map_url && (
                                                    <a
                                                        href={branch.map_url}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="inline-flex items-center gap-1.5 text-brand-text hover:underline"
                                                    >
                                                        <Navigation
                                                            size={15}
                                                            aria-hidden="true"
                                                        />
                                                        {t(
                                                            'service.directions',
                                                        )}
                                                    </a>
                                                )}
                                                {branch.allows_pickup && (
                                                    <span className="text-emerald-700 dark:text-emerald-400">
                                                        {t('service.pickup')}
                                                    </span>
                                                )}
                                            </div>
                                            {branch.hours && (
                                                <details className="group mt-3">
                                                    <summary className="inline-flex cursor-pointer items-center gap-1.5 text-sm font-bold">
                                                        <Clock
                                                            size={15}
                                                            aria-hidden="true"
                                                        />
                                                        {t(
                                                            'service.opening_hours',
                                                        )}
                                                    </summary>
                                                    <OpeningHours
                                                        hours={branch.hours}
                                                    />
                                                </details>
                                            )}
                                        </article>
                                    ))}
                                </div>
                            </section>
                        )}

                        {(policies.delivery || policies.returns) && (
                            <section className="grid gap-3">
                                {policies.delivery && (
                                    <details className="rounded-panel border border-line bg-panel p-4">
                                        <summary className="flex cursor-pointer items-center gap-2 font-black">
                                            <Truck
                                                size={18}
                                                aria-hidden="true"
                                            />
                                            {t('service.delivery_policy')}
                                        </summary>
                                        <p className="mt-2 text-sm leading-6 whitespace-pre-line text-ink-soft">
                                            {policies.delivery}
                                        </p>
                                    </details>
                                )}
                                {policies.returns && (
                                    <details className="rounded-panel border border-line bg-panel p-4">
                                        <summary className="flex cursor-pointer items-center gap-2 font-black">
                                            <Undo2
                                                size={18}
                                                aria-hidden="true"
                                            />
                                            {t('service.return_policy')}
                                        </summary>
                                        <p className="mt-2 text-sm leading-6 whitespace-pre-line text-ink-soft">
                                            {policies.returns}
                                        </p>
                                    </details>
                                )}
                            </section>
                        )}
                    </div>

                    <form
                        onSubmit={submit}
                        className="h-fit rounded-panel border border-line bg-panel p-5 sm:p-6"
                    >
                        <h2 className="flex items-center gap-2 text-xl font-black">
                            <Mail size={20} aria-hidden="true" />
                            {t('service.send_message')}
                        </h2>
                        {form.wasSuccessful && flash.success && (
                            <p
                                role="status"
                                className="mt-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800"
                            >
                                {flash.success}
                            </p>
                        )}
                        <label className="mt-4 grid gap-1.5 text-sm font-semibold">
                            {t('service.name')}
                            <input
                                required
                                maxLength={120}
                                autoComplete="name"
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                className="admin-input"
                            />
                        </label>
                        <div className="mt-4 grid gap-4 sm:grid-cols-2">
                            <label className="grid gap-1.5 text-sm font-semibold">
                                {t('service.phone')}
                                <input
                                    type="tel"
                                    dir="ltr"
                                    maxLength={20}
                                    autoComplete="tel"
                                    value={form.data.phone}
                                    onChange={(event) =>
                                        form.setData(
                                            'phone',
                                            event.target.value,
                                        )
                                    }
                                    className="admin-input"
                                />
                            </label>
                            <label className="grid gap-1.5 text-sm font-semibold">
                                {t('service.email')}
                                <input
                                    type="email"
                                    dir="ltr"
                                    maxLength={255}
                                    autoComplete="email"
                                    value={form.data.email}
                                    onChange={(event) =>
                                        form.setData(
                                            'email',
                                            event.target.value,
                                        )
                                    }
                                    className="admin-input"
                                />
                            </label>
                        </div>
                        <p className="mt-1.5 text-xs text-ink-soft">
                            {t('service.phone_or_email')}
                        </p>
                        <label className="mt-4 grid gap-1.5 text-sm font-semibold">
                            {t('service.message')}
                            <textarea
                                required
                                rows={5}
                                minLength={5}
                                maxLength={3000}
                                value={form.data.message}
                                onChange={(event) =>
                                    form.setData('message', event.target.value)
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
                        {error && (
                            <p
                                role="alert"
                                className="mt-3 text-sm font-semibold text-rose-700"
                            >
                                {error}
                            </p>
                        )}
                        <button
                            type="submit"
                            disabled={
                                form.processing ||
                                (!!turnstileSiteKey &&
                                    !form.data.turnstile_token)
                            }
                            className="mt-5 h-12 w-full rounded-btn bg-brand font-bold text-on-brand transition hover:bg-brand-hover disabled:opacity-60"
                        >
                            {t(
                                form.processing
                                    ? 'service.sending'
                                    : 'service.send',
                            )}
                        </button>
                    </form>
                </div>
            </main>
        </StorefrontLayout>
    );
}
