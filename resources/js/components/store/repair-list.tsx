import { usePage } from '@inertiajs/react';
import { useTranslations } from '@/hooks/use-translations';
import { formatMoney } from '@/lib/store';

export type RepairRequest = {
    id: number;
    device: string;
    status: string;
    estimated_cost: string | null;
    created_at: string | null;
};

export function RepairList({ requests }: { requests: RepairRequest[] }) {
    const { locale } = usePage().props;
    const t = useTranslations();

    return (
        <ul className="divide-y divide-line">
            {requests.map((request) => (
                <li
                    key={request.id}
                    className="flex flex-wrap items-center justify-between gap-2 py-3 text-sm"
                >
                    <span className="min-w-0">
                        <span className="font-bold">#{request.id}</span>{' '}
                        {request.device}
                        {request.created_at && (
                            <span className="block text-xs text-ink-soft">
                                {new Date(
                                    request.created_at,
                                ).toLocaleDateString(locale)}
                            </span>
                        )}
                    </span>
                    <span className="flex items-center gap-3">
                        {request.estimated_cost && (
                            <span className="text-xs text-ink-soft">
                                {t('service.estimated_cost')}:{' '}
                                <span dir="ltr" className="font-bold text-ink">
                                    {formatMoney(
                                        request.estimated_cost,
                                        'ILS',
                                        'en',
                                    )}
                                </span>
                            </span>
                        )}
                        <span className="rounded-full bg-brand/10 px-2.5 py-1 text-xs font-bold text-brand-text">
                            {t(`service.repair_status_${request.status}`)}
                        </span>
                    </span>
                </li>
            ))}
        </ul>
    );
}
