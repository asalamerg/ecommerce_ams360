import { Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    BadgeCheck,
    ClipboardCheck,
    Headphones,
    Lock,
    PackageSearch,
    ShieldCheck,
} from 'lucide-react';
import { useTranslations } from '@/hooks/use-translations';
import { cn } from '@/lib/utils';
import type { AuthLayoutProps } from '@/types';

const locales = [
    { code: 'ar', label: 'العربية' },
    { code: 'he', label: 'עברית' },
    { code: 'en', label: 'English' },
];

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { locale, direction, staffLogin, name: appName } = usePage().props;
    const t = useTranslations();
    const isStaff = staffLogin === true;
    const BackIcon = direction === 'rtl' ? ArrowRight : ArrowLeft;

    const benefits = isStaff
        ? [
              { icon: ShieldCheck, label: t('auth.staff_benefit_access') },
              { icon: ClipboardCheck, label: t('auth.staff_benefit_audit') },
              { icon: Lock, label: t('auth.staff_benefit_alerts') },
          ]
        : [
              { icon: BadgeCheck, label: t('auth.benefit_secure') },
              { icon: PackageSearch, label: t('auth.benefit_orders') },
              { icon: Headphones, label: t('auth.benefit_support') },
          ];

    return (
        <div
            dir={direction}
            className="grid min-h-svh bg-surface text-ink lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]"
        >
            <aside
                className={cn(
                    'relative hidden overflow-hidden bg-topbar p-12 text-on-topbar lg:flex lg:flex-col lg:justify-between',
                )}
            >
                <div
                    aria-hidden="true"
                    className="absolute -end-32 -top-32 h-96 w-96 rounded-full bg-brand/30 blur-3xl"
                />
                <div
                    aria-hidden="true"
                    className="absolute -start-24 bottom-0 h-72 w-72 rounded-full bg-orange-400/10 blur-3xl"
                />

                <Link
                    href={`/${locale}`}
                    className="relative text-2xl font-black tracking-tight"
                >
                    By<span className="text-brand-text">TechM</span>
                </Link>

                <div className="relative max-w-md animate-in duration-700 fade-in slide-in-from-bottom-3 motion-reduce:animate-none">
                    <h2 className="text-4xl leading-tight font-black">
                        {t(
                            isStaff
                                ? 'auth.staff_brand_title'
                                : 'auth.brand_title',
                        )}
                    </h2>
                    <p className="mt-4 text-lg opacity-80">
                        {t(
                            isStaff
                                ? 'auth.staff_brand_description'
                                : 'auth.brand_description',
                        )}
                    </p>
                    <ul className="mt-10 space-y-4">
                        {benefits.map(({ icon: Icon, label }) => (
                            <li
                                key={label}
                                className="flex items-center gap-3 text-slate-200"
                            >
                                <span className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-white/10 text-brand-text">
                                    <Icon size={18} aria-hidden="true" />
                                </span>
                                {label}
                            </li>
                        ))}
                    </ul>
                </div>

                <span className="relative text-sm text-ink-soft">
                    © {new Date().getFullYear()} {appName}
                </span>
            </aside>

            <main className="flex flex-col">
                <div className="flex items-center justify-between gap-4 px-6 py-5 text-sm font-semibold sm:px-10">
                    <Link
                        href={`/${locale}`}
                        className="inline-flex items-center gap-2 text-ink-soft transition hover:text-brand-text"
                    >
                        <BackIcon size={16} aria-hidden="true" />
                        {t('auth.back_to_store')}
                    </Link>
                    <nav
                        className="flex items-center gap-3"
                        aria-label="Language"
                    >
                        {locales.map((item) => (
                            <a
                                key={item.code}
                                href={`?lang=${item.code}`}
                                aria-current={
                                    locale === item.code ? 'true' : undefined
                                }
                                className={cn(
                                    'transition hover:text-brand-text',
                                    locale === item.code
                                        ? 'text-brand-text'
                                        : 'text-ink-soft',
                                )}
                            >
                                {item.label}
                            </a>
                        ))}
                    </nav>
                </div>

                <div className="flex flex-1 items-center justify-center px-6 pb-12 sm:px-10">
                    <div className="w-full max-w-md animate-in duration-500 fade-in slide-in-from-bottom-2 motion-reduce:animate-none">
                        <Link
                            href={`/${locale}`}
                            className="mb-8 block text-center text-3xl font-black tracking-tight lg:hidden"
                        >
                            By<span className="text-brand-text">TechM</span>
                        </Link>
                        <div className="rounded-3xl border border-line bg-panel p-6 shadow-sm sm:p-8">
                            <div className="mb-7">
                                {isStaff && (
                                    <span className="mb-3 inline-flex items-center gap-1.5 rounded-full bg-ink px-3 py-1 text-xs font-bold text-panel">
                                        <ShieldCheck
                                            size={14}
                                            aria-hidden="true"
                                        />
                                        {appName} Admin
                                    </span>
                                )}
                                <h1 className="text-2xl font-black">{title}</h1>
                                <p className="mt-2 text-sm text-ink-soft">
                                    {description}
                                </p>
                            </div>
                            {children}
                        </div>
                    </div>
                </div>
            </main>
        </div>
    );
}
