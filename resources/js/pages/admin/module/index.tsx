import { Link, router } from '@inertiajs/react';
import { Inbox, Plus } from 'lucide-react';
import {
    EmptyState,
    PageHeader,
    Pagination,
    SearchBox,
    panel,
    primaryButton,
} from '@/components/admin/admin-ui';
import { useAdminText } from '@/hooks/use-admin-text';
import { savedRowClass, useSavedRow } from '@/hooks/use-saved-row';
import StoreAdminLayout from '@/layouts/store-admin-layout';
import { cn } from '@/lib/utils';
import type { PageLink } from '@/types';

type Row = {
    id: number;
    values: Record<string, string | boolean>;
    summary: Record<string, string>;
};
type PaginatedRows = { data: Row[]; links: PageLink[]; total: number };
type Module = {
    key: string;
    title: string;
    description: string;
    columns: Record<string, string>;
    canCreate: boolean;
    canDelete: boolean;
    singleton: boolean;
    searchable: boolean;
};

export default function StoreModuleIndex({
    module,
    records,
    search,
}: {
    module: Module;
    records: PaginatedRows | Row[];
    search: string;
}) {
    const t = useAdminText();
    const { withBack, highlight, savedRowRef } = useSavedRow();
    const paginated = !Array.isArray(records);
    const rows = paginated ? records.data : records;
    const links = paginated ? records.links : [];
    const total = paginated ? records.total : rows.length;
    const canAdd = module.canCreate && (!module.singleton || rows.length === 0);
    const createUrl = withBack(`/admin/modules/${module.key}/create`);

    return (
        <StoreAdminLayout title={module.title}>
            <PageHeader
                title={module.title}
                description={`${module.description} · ${t(':count records', { count: total })}`}
            >
                <div className="flex w-full flex-wrap items-center gap-3 sm:w-auto">
                    {module.searchable && (
                        <SearchBox
                            value={search}
                            url={`/admin/modules/${module.key}`}
                        />
                    )}
                    {canAdd && (
                        <Link href={createUrl} className={primaryButton}>
                            <Plus size={18} aria-hidden="true" />
                            {t('Add')}
                        </Link>
                    )}
                </div>
            </PageHeader>

            <div className={`${panel} overflow-hidden`}>
                {rows.length ? (
                    <div className="overflow-x-auto">
                        <table className="admin-table w-full min-w-[640px] text-start text-sm">
                            <thead className="bg-surface text-xs text-ink-soft">
                                <tr>
                                    {Object.entries(module.columns).map(
                                        ([key, label]) => (
                                            <th
                                                key={key}
                                                className="px-5 py-3.5 text-start"
                                            >
                                                {label}
                                            </th>
                                        ),
                                    )}
                                    <th className="px-5 py-3.5 text-end">
                                        {t('Actions')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {rows.map((row) => (
                                    <tr
                                        key={row.id}
                                        ref={
                                            highlight === row.id
                                                ? savedRowRef
                                                : undefined
                                        }
                                        className={cn(
                                            'transition hover:bg-surface/60',
                                            highlight === row.id &&
                                                savedRowClass,
                                        )}
                                    >
                                        {Object.entries(module.columns).map(
                                            ([key, label], index) => (
                                                <td
                                                    key={key}
                                                    data-cell={
                                                        index === 0
                                                            ? 'primary'
                                                            : undefined
                                                    }
                                                    data-label={
                                                        index === 0
                                                            ? undefined
                                                            : label
                                                    }
                                                    className="max-w-72 truncate px-5 py-3.5"
                                                >
                                                    {[
                                                        'image_url',
                                                        'logo_url',
                                                    ].includes(key) &&
                                                    row.summary[key] ? (
                                                        <img
                                                            src={
                                                                row.summary[key]
                                                            }
                                                            alt=""
                                                            loading="lazy"
                                                            className="h-12 w-16 rounded-lg bg-surface object-contain"
                                                        />
                                                    ) : (
                                                        row.summary[key] || '—'
                                                    )}
                                                </td>
                                            ),
                                        )}
                                        <td
                                            data-cell="actions"
                                            className="px-5 py-3.5"
                                        >
                                            <div className="flex justify-end gap-4">
                                                <Link
                                                    href={withBack(
                                                        `/admin/modules/${module.key}/${row.id}/edit`,
                                                    )}
                                                    className="font-bold text-brand-text hover:underline"
                                                >
                                                    {t('Manage')}
                                                </Link>
                                                {module.canDelete && (
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            router.delete(
                                                                `/admin/modules/${module.key}/${row.id}`,
                                                                {
                                                                    onBefore:
                                                                        () =>
                                                                            window.confirm(
                                                                                t(
                                                                                    'Delete this record?',
                                                                                ),
                                                                            ),
                                                                },
                                                            )
                                                        }
                                                        className="font-semibold text-rose-700 hover:underline"
                                                    >
                                                        {t('Delete')}
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <EmptyState
                        icon={<Inbox aria-hidden="true" />}
                        title={t(
                            search ? 'No results found.' : 'No records yet.',
                        )}
                        action={
                            canAdd && !search ? (
                                <Link
                                    href={createUrl}
                                    className={primaryButton}
                                >
                                    {t('Add')}
                                </Link>
                            ) : undefined
                        }
                    />
                )}
            </div>

            <Pagination links={links} />
        </StoreAdminLayout>
    );
}
