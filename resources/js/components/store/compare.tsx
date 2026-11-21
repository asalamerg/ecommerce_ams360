import { Link, usePage } from '@inertiajs/react';
import { GitCompareArrows, X } from 'lucide-react';
import { useState } from 'react';
import {
    MAX_COMPARE,
    clearCompare,
    toggleCompare,
    useCompare,
} from '@/hooks/use-compare';
import { useTranslations } from '@/hooks/use-translations';
import { cn } from '@/lib/utils';

/** "Compare" on the product page: adds or removes the product. */
export function CompareButton({ productId }: { productId: number }) {
    const t = useTranslations();
    const ids = useCompare();
    const [full, setFull] = useState(false);
    const picked = ids.includes(productId);

    return (
        <div>
            <button
                type="button"
                aria-pressed={picked}
                onClick={() => setFull(!toggleCompare(productId))}
                className={cn(
                    'inline-flex items-center gap-2 rounded-btn border px-4 py-2 text-sm font-bold transition',
                    picked
                        ? 'border-brand bg-brand/10 text-brand-text'
                        : 'border-line hover:border-brand hover:text-brand-text',
                )}
            >
                <GitCompareArrows size={16} aria-hidden="true" />
                {picked ? t('compare.added') : t('compare.add')}
            </button>
            {full && !picked && (
                <p role="status" className="mt-1 text-xs text-rose-700">
                    {t('compare.full', { count: MAX_COMPARE })}
                </p>
            )}
        </div>
    );
}

/**
 * Shown at the bottom of the storefront once something is picked, so the
 * comparison is always one tap away. Hidden on the comparison page itself.
 */
export function CompareBar() {
    const { url, props } = usePage();
    const { locale } = props;
    const t = useTranslations();
    const ids = useCompare();

    if (!ids.length || url.split('?')[0] === `/${locale}/compare`) {
        return null;
    }

    return (
        <div className="fixed inset-x-0 bottom-[calc(4rem+env(safe-area-inset-bottom))] z-30 px-4 md:bottom-4">
            <div className="mx-auto flex max-w-md items-center gap-3 rounded-full border border-line bg-panel py-2 ps-5 pe-2 shadow-xl">
                <GitCompareArrows
                    size={18}
                    aria-hidden="true"
                    className="text-brand-text"
                />
                <span className="flex-1 text-sm font-bold">
                    {t('compare.selected', {
                        count: ids.length,
                        max: MAX_COMPARE,
                    })}
                </span>
                <button
                    type="button"
                    onClick={clearCompare}
                    aria-label={t('compare.clear')}
                    className="grid h-8 w-8 place-items-center rounded-full text-ink-soft hover:bg-surface"
                >
                    <X size={16} aria-hidden="true" />
                </button>
                <Link
                    href={`/${locale}/compare`}
                    className="rounded-full bg-brand px-4 py-2 text-sm font-bold text-on-brand transition hover:bg-brand-hover"
                >
                    {t('compare.open')}
                </Link>
            </div>
        </div>
    );
}
