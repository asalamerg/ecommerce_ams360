import { Check, MessageCircle, Smartphone } from 'lucide-react';
import SeoTitle from '@/components/store/seo-title';
import { useTranslations } from '@/hooks/use-translations';
import StorefrontLayout from '@/layouts/storefront-layout';
import { formatMoney } from '@/lib/store';

type Plan = {
    id: number;
    carrier: string;
    name: string;
    data_gb: number | null;
    minutes: number | null;
    sms: number | null;
    monthly_price: string;
    contract_months: number;
    features: string[];
};

export default function SimPlans({
    plans,
    whatsapp,
}: {
    plans: Plan[];
    whatsapp: string | null;
}) {
    const t = useTranslations();

    return (
        <StorefrontLayout>
            <SeoTitle />
            <main className="mx-auto max-w-6xl px-4 py-8 sm:px-6 sm:py-12 lg:px-8">
                <Smartphone
                    size={44}
                    aria-hidden="true"
                    className="text-brand-text"
                />
                <h1 className="mt-3 text-3xl font-black sm:text-4xl">
                    {t('service.sim_title')}
                </h1>
                <p className="mt-2 max-w-2xl text-ink-soft">
                    {t('service.sim_intro')}
                </p>

                {plans.length === 0 ? (
                    <p className="mt-10 rounded-panel border border-line bg-panel p-8 text-center text-ink-soft">
                        {t('service.no_plans')}
                    </p>
                ) : (
                    <ul className="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {plans.map((plan) => {
                            const lines = [
                                plan.data_gb !== null &&
                                    t('service.data', {
                                        count: plan.data_gb,
                                    }),
                                plan.minutes !== null &&
                                    t('service.minutes', {
                                        count: plan.minutes,
                                    }),
                                plan.sms !== null &&
                                    t('service.sms', { count: plan.sms }),
                                plan.contract_months > 0
                                    ? t('service.contract', {
                                          count: plan.contract_months,
                                      })
                                    : t('service.no_contract'),
                                ...plan.features,
                            ].filter((line): line is string => !!line);

                            return (
                                <li
                                    key={plan.id}
                                    className="card-surface flex flex-col rounded-panel p-5"
                                >
                                    <p className="text-sm font-bold text-brand-text">
                                        {plan.carrier}
                                    </p>
                                    <h2 className="mt-1 text-xl font-black">
                                        {plan.name}
                                    </h2>
                                    <p className="mt-3" dir="ltr">
                                        <span className="block text-start rtl:text-end">
                                            <span className="text-3xl font-black">
                                                {formatMoney(
                                                    plan.monthly_price,
                                                    'ILS',
                                                    'en',
                                                )}
                                            </span>
                                            <span className="text-sm text-ink-soft">
                                                {t('service.per_month')}
                                            </span>
                                        </span>
                                    </p>
                                    <ul className="mt-4 flex-1 space-y-2 text-sm">
                                        {lines.map((line) => (
                                            <li
                                                key={line}
                                                className="flex items-start gap-2"
                                            >
                                                <Check
                                                    size={16}
                                                    aria-hidden="true"
                                                    className="mt-0.5 shrink-0 text-emerald-600"
                                                />
                                                {line}
                                            </li>
                                        ))}
                                    </ul>
                                    {whatsapp && (
                                        <a
                                            href={`${whatsapp}?text=${encodeURIComponent(
                                                t('service.order_plan_text', {
                                                    plan: plan.name,
                                                    carrier: plan.carrier,
                                                }),
                                            )}`}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="mt-5 inline-flex h-11 items-center justify-center gap-2 rounded-btn bg-[#25D366] font-bold text-[#0b3d1f] transition hover:bg-[#1ebe5b]"
                                        >
                                            <MessageCircle
                                                size={18}
                                                aria-hidden="true"
                                            />
                                            {t('service.order_plan')}
                                        </a>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                )}
            </main>
        </StorefrontLayout>
    );
}
