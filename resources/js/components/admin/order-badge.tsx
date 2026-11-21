import { cn } from '@/lib/utils';

export type OrderOptions = {
    statuses: Record<string, string>;
    payment_statuses: Record<string, string>;
};

export const statusTone: Record<string, string> = {
    pending: 'bg-amber-100 text-amber-900',
    processing: 'bg-sky-100 text-sky-900',
    ready_for_pickup: 'bg-violet-100 text-violet-900',
    shipped: 'bg-indigo-100 text-indigo-900',
    completed: 'bg-emerald-100 text-emerald-900',
    cancelled: 'bg-rose-100 text-rose-900',
    unpaid: 'bg-surface text-ink-soft',
    paid: 'bg-emerald-100 text-emerald-900',
    failed: 'bg-rose-100 text-rose-900',
    refunded: 'bg-slate-200 text-slate-800',
    review: 'bg-orange-200 text-orange-950',
};

export function Badge({ value, label }: { value: string; label: string }) {
    return (
        <span
            className={cn(
                'inline-flex rounded-full px-2.5 py-1 text-xs font-bold whitespace-nowrap',
                statusTone[value] ?? 'bg-surface text-ink-soft',
            )}
        >
            {label}
        </span>
    );
}
