import { Form, Head, Link, usePage } from '@inertiajs/react';
import { BadgeCheck, CircleAlert } from 'lucide-react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import PointsHistory from '@/components/store/points-history';
import type { AccountPoints } from '@/components/store/points-history';
import { RepairList } from '@/components/store/repair-list';
import type { RepairRequest } from '@/components/store/repair-list';
import SupportTickets from '@/components/store/support-tickets';
import type { Ticket } from '@/components/store/support-tickets';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/hooks/use-translations';
import StorefrontLayout from '@/layouts/storefront-layout';
import { formatMoney } from '@/lib/store';
import { send } from '@/routes/verification';

type Props = {
    account: {
        name: string;
        email: string;
        phone: string | null;
        preferred_locale: string;
        email_verified: boolean;
    };
    status?: string;
    points: AccountPoints | null;
    tickets: Ticket[];
    repairs: RepairRequest[];
    orders: {
        reference: string;
        status: string;
        total: string;
        created_at: string | null;
    }[];
};

const languages = [
    { code: 'ar', label: 'العربية' },
    { code: 'he', label: 'עברית' },
    { code: 'en', label: 'English' },
];

const saveButton =
    'h-11 bg-brand px-6 font-bold text-on-brand transition hover:bg-brand-hover';

export default function Account({
    account,
    status,
    points,
    tickets,
    repairs,
    orders,
}: Props) {
    const { locale } = usePage().props;
    const t = useTranslations();

    return (
        <StorefrontLayout>
            <Head title={t('account.title')} />
            <main className="mx-auto max-w-3xl px-4 py-10 sm:px-6">
                <h1 className="text-3xl font-black">{t('account.title')}</h1>
                <p className="mt-2 text-ink-soft">{t('account.description')}</p>

                {status === 'verification-link-sent' && (
                    <div
                        role="status"
                        className="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800"
                    >
                        {t('auth.verification_sent')}
                    </div>
                )}

                <section className="mt-8 rounded-3xl border border-line bg-panel p-6 sm:p-8">
                    <h2 className="text-xl font-black">
                        {t('account.profile')}
                    </h2>

                    <div className="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-surface px-4 py-3 text-sm">
                        <span dir="ltr" className="font-semibold">
                            {account.email}
                        </span>
                        {account.email_verified ? (
                            <span className="inline-flex items-center gap-1.5 font-semibold text-emerald-700">
                                <BadgeCheck size={16} aria-hidden="true" />
                                {t('account.verified')}
                            </span>
                        ) : (
                            <span className="inline-flex items-center gap-1.5 font-semibold text-amber-700">
                                <CircleAlert size={16} aria-hidden="true" />
                                {t('account.unverified')}
                            </span>
                        )}
                    </div>

                    {!account.email_verified && (
                        <Form {...send.form()} className="mt-3">
                            {({ processing }) => (
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="text-sm font-semibold text-brand-text underline underline-offset-4 disabled:opacity-60"
                                >
                                    {t('account.resend_verification')}
                                </button>
                            )}
                        </Form>
                    )}

                    <Form
                        action={`/${locale}/account`}
                        method="put"
                        options={{ preserveScroll: true }}
                        className="mt-6 grid gap-5"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="name">
                                        {t('auth.name')}
                                    </Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        defaultValue={account.name}
                                        autoComplete="name"
                                        required
                                    />
                                    <InputError message={errors.name} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="phone">
                                        {t('account.phone')}
                                    </Label>
                                    <Input
                                        id="phone"
                                        name="phone"
                                        type="tel"
                                        dir="ltr"
                                        defaultValue={account.phone ?? ''}
                                        autoComplete="tel"
                                    />
                                    <InputError message={errors.phone} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="preferred_locale">
                                        {t('account.language')}
                                    </Label>
                                    <select
                                        id="preferred_locale"
                                        name="preferred_locale"
                                        defaultValue={account.preferred_locale}
                                        className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                                    >
                                        {languages.map((language) => (
                                            <option
                                                key={language.code}
                                                value={language.code}
                                            >
                                                {language.label}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError
                                        message={errors.preferred_locale}
                                    />
                                </div>
                                <div>
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className={saveButton}
                                    >
                                        {processing && <Spinner />}
                                        {t('account.save')}
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </section>

                <section className="mt-6 rounded-3xl border border-line bg-panel p-6 sm:p-8">
                    <h2 className="text-xl font-black">
                        {t('account.security')}
                    </h2>
                    <Form
                        action={`/${locale}/account/password`}
                        method="put"
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="mt-6 grid gap-5"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="current_password">
                                        {t('account.current_password')}
                                    </Label>
                                    <PasswordInput
                                        id="current_password"
                                        name="current_password"
                                        autoComplete="current-password"
                                        required
                                    />
                                    <InputError
                                        message={errors.current_password}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="password">
                                        {t('account.new_password')}
                                    </Label>
                                    <PasswordInput
                                        id="password"
                                        name="password"
                                        autoComplete="new-password"
                                        required
                                    />
                                    <InputError message={errors.password} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="password_confirmation">
                                        {t('auth.confirm_password')}
                                    </Label>
                                    <PasswordInput
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        autoComplete="new-password"
                                        required
                                    />
                                </div>
                                <div>
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className={saveButton}
                                    >
                                        {processing && <Spinner />}
                                        {t('account.update_password')}
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </section>

                {points && <PointsHistory points={points} locale={locale} />}

                <section className="mt-6 rounded-3xl border border-line bg-panel p-6 sm:p-8">
                    <h2 className="text-xl font-black">
                        {t('checkout.my_orders')}
                    </h2>
                    {orders.length ? (
                        <ul className="mt-4 divide-y divide-line">
                            {orders.map((order) => (
                                <li key={order.reference}>
                                    <Link
                                        href={`/${locale}/orders/${order.reference}`}
                                        className="flex items-center justify-between gap-3 py-3 transition hover:text-brand-text"
                                    >
                                        <span>
                                            <span
                                                className="block font-bold"
                                                dir="ltr"
                                            >
                                                {order.reference}
                                            </span>
                                            <span className="text-xs text-ink-soft">
                                                {order.status}
                                            </span>
                                        </span>
                                        <span className="font-black" dir="ltr">
                                            {formatMoney(
                                                order.total,
                                                'ILS',
                                                'en',
                                            )}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="mt-3 text-sm text-ink-soft">
                            {t('checkout.no_orders')}
                        </p>
                    )}
                </section>

                {repairs.length > 0 && (
                    <section className="mt-6 rounded-3xl border border-line bg-panel p-6 sm:p-8">
                        <h2 className="text-xl font-black">
                            {t('service.my_repairs')}
                        </h2>
                        <RepairList requests={repairs} />
                    </section>
                )}

                <SupportTickets tickets={tickets} />
            </main>
        </StorefrontLayout>
    );
}
