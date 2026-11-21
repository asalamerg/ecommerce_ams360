import { PageHeader, Pagination } from '@/components/admin/admin-ui';
import { useAdminText, useDateFormatter } from '@/hooks/use-admin-text';
import StoreAdminLayout from '@/layouts/store-admin-layout';
import type { PageLink } from '@/types';

type Activity = {
    id: number;
    actor: string;
    action: string;
    subject_type: string;
    subject_id: number;
    changes: {
        before: Record<string, unknown> | null;
        after: Record<string, unknown>;
    };
    created_at: string;
};
type StoreActivity = {
    id: number;
    actor: string;
    section: string;
    action: string;
    record_id: number | null;
    changes: { fields?: string[] };
    created_at: string;
};
type AuthEvent = {
    id: number;
    event: string;
    actor: string | null;
    ip_address: string | null;
    created_at: string;
};

function describeAction(action: string): string {
    const labels: Record<string, string> = {
        'product.created': 'Created product',
        'product.updated': 'Updated product',
        'product.archived': 'Archived product',
        'product.shown': 'Showed product',
        'product.hidden': 'Hid product',
        'product.deleted': 'Deleted product permanently',
        'category.created': 'Created category',
        'category.updated': 'Updated category',
        'category.archived': 'Archived category',
        'category.shown': 'Showed category',
        'category.hidden': 'Hid category',
        'category.deleted': 'Deleted category permanently',
    };

    return labels[action] ?? action;
}

const authEventLabels: Record<string, string> = {
    login: 'Signed in',
    logout: 'Signed out',
    failed: 'Failed sign-in',
    two_factor_failed: 'Failed two-factor code',
    password_reset: 'Password reset',
    email_verified: 'Email confirmed',
};

export default function ActivityIndex({
    activities,
    storeActivities,
    authEvents,
}: {
    activities: { data: Activity[]; links: PageLink[]; total: number };
    storeActivities: StoreActivity[];
    authEvents: AuthEvent[];
}) {
    const t = useAdminText();
    const formatDate = useDateFormatter();

    return (
        <StoreAdminLayout title={t('Activity log')}>
            <PageHeader
                eyebrow={t('Security')}
                title={t('Catalog activity')}
                description={t(
                    'A history of product and category changes made by store staff.',
                )}
            />
            <div className="space-y-4">
                {activities.data.length ? (
                    activities.data.map((activity) => (
                        <article
                            key={activity.id}
                            className="rounded-panel border border-line bg-panel p-5"
                        >
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h2 className="font-bold">
                                        {t(describeAction(activity.action))}
                                    </h2>
                                    <p className="mt-1 text-sm text-ink-soft">
                                        {activity.actor} ·{' '}
                                        {activity.subject_type} #
                                        {activity.subject_id}
                                    </p>
                                </div>
                                <time
                                    className="text-xs text-ink-soft"
                                    dateTime={activity.created_at}
                                >
                                    {formatDate(activity.created_at)}
                                </time>
                            </div>
                            <details className="mt-4">
                                <summary className="cursor-pointer text-sm font-semibold text-brand-text">
                                    {t('View changed fields')}
                                </summary>
                                <pre className="mt-3 overflow-x-auto rounded-xl bg-slate-950 p-4 text-xs leading-5 text-slate-100">
                                    {JSON.stringify(activity.changes, null, 2)}
                                </pre>
                            </details>
                        </article>
                    ))
                ) : (
                    <div className="rounded-panel border border-dashed border-line bg-panel px-6 py-16 text-center text-ink-soft">
                        {t('No catalog changes have been recorded yet.')}
                    </div>
                )}
            </div>
            <Pagination links={activities.links} />
            <section className="mt-10">
                <h2 className="text-xl font-black">
                    {t('Store administration')}
                </h2>
                <p className="mt-2 text-sm text-ink-soft">
                    {t(
                        'Recent changes across promotions, orders, support, users, and store settings.',
                    )}
                </p>
                <div className="mt-4 space-y-3">
                    {storeActivities.length ? (
                        storeActivities.map((activity) => (
                            <article
                                key={activity.id}
                                className="rounded-panel border border-line bg-panel p-4"
                            >
                                <div className="flex flex-wrap justify-between gap-2">
                                    <p className="font-bold">
                                        {t(activity.section)} ·{' '}
                                        {t(activity.action)}
                                        {activity.record_id
                                            ? ` #${activity.record_id}`
                                            : ''}
                                    </p>
                                    <time
                                        className="text-xs text-ink-soft"
                                        dateTime={activity.created_at}
                                    >
                                        {formatDate(activity.created_at)}
                                    </time>
                                </div>
                                <p className="mt-1 text-sm text-ink-soft">
                                    {activity.actor} · {t('Fields:')}{' '}
                                    {activity.changes.fields?.join(', ') || '—'}
                                </p>
                            </article>
                        ))
                    ) : (
                        <p className="rounded-xl bg-panel p-4 text-sm text-ink-soft">
                            {t(
                                'No store-module changes have been recorded yet.',
                            )}
                        </p>
                    )}
                </div>
            </section>
            {authEvents.length > 0 && (
                <section className="mt-10">
                    <h2 className="text-xl font-black">
                        {t('Sign-in activity')}
                    </h2>
                    <p className="mt-2 text-sm text-ink-soft">
                        {t(
                            'Recent sign-ins, sign-outs, and failed attempts. Visible to administrators only.',
                        )}
                    </p>
                    <div className="mt-4 overflow-x-auto rounded-panel border border-line bg-panel">
                        <table className="admin-table w-full text-sm">
                            <thead className="bg-surface text-start text-xs text-ink-soft">
                                <tr>
                                    <th className="p-3 text-start">
                                        {t('Event')}
                                    </th>
                                    <th className="p-3 text-start">
                                        {t('Account')}
                                    </th>
                                    <th className="p-3 text-start">
                                        {t('IP address')}
                                    </th>
                                    <th className="p-3 text-start">
                                        {t('Time')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {authEvents.map((event) => (
                                    <tr
                                        key={event.id}
                                        className="border-t border-line"
                                    >
                                        <td
                                            data-cell="primary"
                                            className="p-3 font-semibold"
                                        >
                                            {t(
                                                authEventLabels[event.event] ??
                                                    event.event,
                                            )}
                                        </td>
                                        <td
                                            data-label={t('Account')}
                                            className="p-3 text-ink-soft"
                                        >
                                            {event.actor ??
                                                t('Unknown account')}
                                        </td>
                                        <td
                                            data-label={t('IP address')}
                                            dir="ltr"
                                            className="p-3 text-ink-soft"
                                        >
                                            {event.ip_address ?? '—'}
                                        </td>
                                        <td
                                            data-label={t('Time')}
                                            className="p-3 text-ink-soft"
                                        >
                                            <time dateTime={event.created_at}>
                                                {formatDate(event.created_at)}
                                            </time>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            )}
        </StoreAdminLayout>
    );
}
