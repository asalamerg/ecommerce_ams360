import { useForm, usePage } from '@inertiajs/react';
import { LifeBuoy } from 'lucide-react';
import type { FormEvent } from 'react';
import { useTranslations } from '@/hooks/use-translations';

export type Ticket = {
    id: number;
    subject: string;
    status: string;
    created_at: string | null;
};

const statusTone: Record<string, string> = {
    open: 'bg-amber-100 text-amber-900',
    in_progress: 'bg-sky-100 text-sky-900',
    closed: 'bg-surface text-ink-soft',
};

/**
 * A signed-in customer's support requests: send a new one and follow the
 * status staff set in the admin's Support tickets screen.
 */
export default function SupportTickets({ tickets }: { tickets: Ticket[] }) {
    const { locale, flash } = usePage().props;
    const t = useTranslations();
    const form = useForm({ subject: '', message: '' });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(`/${locale}/account/tickets`, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    }

    return (
        <section
            id="support"
            className="mt-6 rounded-3xl border border-line bg-panel p-6 sm:p-8"
        >
            <h2 className="flex items-center gap-2 text-xl font-black">
                <LifeBuoy size={20} aria-hidden="true" />
                {t('service.tickets_title')}
            </h2>
            <p className="mt-1 text-sm text-ink-soft">
                {t('service.tickets_intro')}
            </p>

            <form onSubmit={submit} className="mt-4 grid gap-3">
                <label className="grid gap-1.5 text-sm font-semibold">
                    {t('service.subject')}
                    <input
                        required
                        maxLength={150}
                        value={form.data.subject}
                        onChange={(event) =>
                            form.setData('subject', event.target.value)
                        }
                        className="admin-input"
                    />
                </label>
                <label className="grid gap-1.5 text-sm font-semibold">
                    {t('service.message')}
                    <textarea
                        required
                        rows={3}
                        minLength={5}
                        maxLength={3000}
                        value={form.data.message}
                        onChange={(event) =>
                            form.setData('message', event.target.value)
                        }
                        className="admin-input"
                    />
                </label>
                {(form.errors.subject || form.errors.message) && (
                    <p role="alert" className="text-sm text-rose-700">
                        {form.errors.subject || form.errors.message}
                    </p>
                )}
                {form.wasSuccessful && flash.success && (
                    <p
                        role="status"
                        className="rounded-lg bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800"
                    >
                        {flash.success}
                    </p>
                )}
                <button
                    type="submit"
                    disabled={form.processing}
                    className="h-11 w-fit rounded-btn bg-brand px-6 text-sm font-bold text-on-brand transition hover:bg-brand-hover disabled:opacity-60"
                >
                    {t(
                        form.processing
                            ? 'service.sending'
                            : 'service.open_ticket',
                    )}
                </button>
            </form>

            {tickets.length > 0 ? (
                <ul className="mt-5 divide-y divide-line border-t border-line">
                    {tickets.map((ticket) => (
                        <li
                            key={ticket.id}
                            className="flex items-center justify-between gap-3 py-3 text-sm"
                        >
                            <span className="min-w-0">
                                <span className="block truncate font-bold">
                                    {ticket.subject}
                                </span>
                                {ticket.created_at && (
                                    <span className="text-xs text-ink-soft">
                                        {new Date(
                                            ticket.created_at,
                                        ).toLocaleDateString(locale)}
                                    </span>
                                )}
                            </span>
                            <span
                                className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-bold ${statusTone[ticket.status] ?? statusTone.closed}`}
                            >
                                {t(`service.ticket_status_${ticket.status}`)}
                            </span>
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="mt-4 text-sm text-ink-soft">
                    {t('service.no_tickets')}
                </p>
            )}
        </section>
    );
}
