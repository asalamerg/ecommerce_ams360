import { router } from '@inertiajs/react';
import { Eye, EyeOff, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useAdminText } from '@/hooks/use-admin-text';
import { cn } from '@/lib/utils';

/**
 * Shown or hidden in the store, switched in one click. Hidden items stay in
 * the admin panel and keep their links, prices and history.
 */
export function VisibilityToggle({
    visible,
    url,
}: {
    visible: boolean;
    url: string;
}) {
    const t = useAdminText();
    const [busy, setBusy] = useState(false);
    const Icon = visible ? Eye : EyeOff;

    return (
        <button
            type="button"
            disabled={busy}
            onClick={() =>
                router.patch(
                    url,
                    {},
                    {
                        preserveScroll: true,
                        onStart: () => setBusy(true),
                        onFinish: () => setBusy(false),
                    },
                )
            }
            title={t(visible ? 'Click to hide' : 'Click to show')}
            aria-pressed={visible}
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-bold transition disabled:opacity-60',
                visible
                    ? 'bg-emerald-100 text-emerald-800 hover:bg-emerald-200'
                    : 'bg-surface text-ink-soft ring-1 ring-line hover:bg-line/40',
            )}
        >
            <Icon size={14} aria-hidden="true" />
            {t(visible ? 'Shown' : 'Hidden')}
        </button>
    );
}

/** Permanent delete, after a confirmation that says what is lost and what is kept. */
export function DeleteButton({
    url,
    confirmText,
}: {
    url: string;
    confirmText: string;
}) {
    const t = useAdminText();

    return (
        <button
            type="button"
            onClick={() =>
                router.delete(url, {
                    preserveScroll: true,
                    onBefore: () => window.confirm(confirmText),
                })
            }
            className="inline-flex items-center gap-1 font-semibold text-rose-700 hover:underline"
        >
            <Trash2 size={14} aria-hidden="true" />
            {t('Delete permanently')}
        </button>
    );
}
