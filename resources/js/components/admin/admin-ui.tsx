import { Link, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';
import { useAdminText } from '@/hooks/use-admin-text';
import { cn } from '@/lib/utils';
import type { PageLink } from '@/types';

export const primaryButton =
    'inline-flex items-center justify-center gap-2 rounded-btn bg-brand px-5 py-2.5 text-sm font-bold text-on-brand shadow-sm transition hover:bg-brand-hover disabled:cursor-not-allowed disabled:opacity-60';

export const secondaryButton =
    'inline-flex items-center justify-center gap-2 rounded-btn border border-line bg-panel px-5 py-2.5 text-sm font-bold text-ink transition hover:border-brand hover:text-brand-text disabled:cursor-not-allowed disabled:opacity-60';

export const panel = 'rounded-panel border border-line bg-panel';

export function PageHeader({
    eyebrow,
    title,
    description,
    children,
}: {
    eyebrow?: string;
    title: string;
    description?: string;
    children?: ReactNode;
}) {
    return (
        <div className="mb-5 flex flex-wrap items-end justify-between gap-4 sm:mb-6">
            <div className="min-w-0">
                {eyebrow && (
                    <p className="text-sm font-bold text-brand-text">
                        {eyebrow}
                    </p>
                )}
                <h1 className="mt-1 text-2xl font-black text-ink sm:text-3xl">
                    {title}
                </h1>
                {description && (
                    <p className="mt-2 max-w-2xl text-sm text-ink-soft">
                        {description}
                    </p>
                )}
            </div>
            {children}
        </div>
    );
}

export function Pagination({ links }: { links: PageLink[] }) {
    const t = useAdminText();

    if (links.length <= 3) {
        return null;
    }

    return (
        <nav
            aria-label={t('Pagination')}
            className="mt-5 flex flex-wrap justify-center gap-1.5"
        >
            {links.map((link, index) =>
                link.url ? (
                    <Link
                        key={`${link.label}-${index}`}
                        href={link.url}
                        preserveScroll
                        className={cn(
                            'grid h-9 min-w-9 place-items-center rounded-lg border px-2.5 text-sm font-semibold transition',
                            link.active
                                ? 'border-brand bg-brand text-on-brand'
                                : 'border-line bg-panel text-ink hover:border-brand',
                        )}
                    >
                        {link.label
                            .replace('&laquo;', '‹')
                            .replace('&raquo;', '›')
                            .replace('Previous', '')
                            .replace('Next', '')
                            .trim()}
                    </Link>
                ) : null,
            )}
        </nav>
    );
}

export function StatusBadge({
    active,
    children,
}: {
    active: boolean;
    children: ReactNode;
}) {
    return (
        <span
            className={cn(
                'inline-flex rounded-full px-2.5 py-1 text-xs font-bold',
                active
                    ? 'bg-emerald-100 text-emerald-800'
                    : 'bg-surface text-ink-soft',
            )}
        >
            {children}
        </span>
    );
}

export function EmptyState({
    icon,
    title,
    description,
    action,
}: {
    icon: ReactNode;
    title: string;
    description?: string;
    action?: ReactNode;
}) {
    return (
        <div className="px-6 py-16 text-center">
            <div className="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-brand/10 text-brand-text">
                {icon}
            </div>
            <h2 className="mt-4 text-lg font-bold text-ink">{title}</h2>
            {description && (
                <p className="mt-1 text-sm text-ink-soft">{description}</p>
            )}
            {action && <div className="mt-5">{action}</div>}
        </div>
    );
}

/** How long typing pauses before the list searches by itself. */
const SEARCH_DELAY_MS = 350;

/**
 * Searches as the admin types (after a short pause), or at once on Enter.
 * The results replace the page in history, so Back leaves the list instead of
 * stepping through every letter typed.
 */
export function SearchBox({ value, url }: { value: string; url: string }) {
    const t = useAdminText();
    const [term, setTerm] = useState(value);
    const timer = useRef<number | undefined>(undefined);

    function search(next: string) {
        window.clearTimeout(timer.current);
        router.get(url, next.trim() ? { q: next.trim() } : {}, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    function type(next: string) {
        setTerm(next);
        window.clearTimeout(timer.current);

        if (next.trim() !== value.trim()) {
            timer.current = window.setTimeout(
                () => search(next),
                SEARCH_DELAY_MS,
            );
        }
    }

    useEffect(() => () => window.clearTimeout(timer.current), []);

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        search(term);
    }

    return (
        <form
            onSubmit={submit}
            role="search"
            className="relative w-full sm:w-72"
        >
            <Search
                size={16}
                aria-hidden="true"
                className="pointer-events-none absolute start-3 top-1/2 -translate-y-1/2 text-ink-soft"
            />
            <input
                type="search"
                value={term}
                onChange={(event) => type(event.target.value)}
                placeholder={t('Search')}
                aria-label={t('Search')}
                className="admin-input ps-9"
            />
        </form>
    );
}

export function FieldError({ message }: { message?: string }) {
    return message ? (
        <span className="text-xs font-medium text-rose-700">{message}</span>
    ) : null;
}

const languageNames = { ar: 'العربية', en: 'English', he: 'עברית' } as const;

export type ContentLocale = keyof typeof languageNames;

export const contentLocales = Object.keys(languageNames) as ContentLocale[];

/**
 * Switches between the three content languages. A tab holding a validation error
 * is marked, so a hidden error can still be found.
 */
export function LanguageTabs({
    active,
    onChange,
    withErrors = [],
}: {
    active: ContentLocale;
    onChange: (locale: ContentLocale) => void;
    withErrors?: ContentLocale[];
}) {
    return (
        <div role="tablist" className="flex gap-1 border-b border-line">
            {contentLocales.map((locale) => (
                <button
                    key={locale}
                    type="button"
                    role="tab"
                    aria-selected={active === locale}
                    onClick={() => onChange(locale)}
                    className={cn(
                        '-mb-px inline-flex items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-bold transition',
                        active === locale
                            ? 'border-brand text-brand-text'
                            : 'border-transparent text-ink-soft hover:text-ink',
                    )}
                >
                    {languageNames[locale]}
                    {withErrors.includes(locale) && (
                        <span
                            aria-hidden="true"
                            className="h-2 w-2 rounded-full bg-rose-600"
                        />
                    )}
                </button>
            ))}
            {active !== 'ar' && (
                <p className="ms-auto self-center px-2 text-xs text-ink-soft">
                    <LanguageHint />
                </p>
            )}
        </div>
    );
}

function LanguageHint() {
    const t = useAdminText();

    return <>{t('Leave empty to use the Arabic text.')}</>;
}
