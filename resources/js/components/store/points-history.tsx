import { Gift } from 'lucide-react';
import { useTranslations } from '@/hooks/use-translations';
import { formatMoney } from '@/lib/store';

export type AccountPoints = {
    balance: number;
    value: string;
    history: {
        type: string;
        points: number;
        balance_after: number;
        reference: string | null;
        created_at: string | null;
    }[];
};

/** The customer's points balance and latest movements, on the account page. */
export default function PointsHistory({
    points,
    locale,
}: {
    points: AccountPoints;
    locale: string;
}) {
    const t = useTranslations();

    return (
        <section className="mt-6 rounded-3xl border border-line bg-panel p-6 sm:p-8">
            <div className="flex flex-wrap items-center justify-between gap-4">
                <h2 className="flex items-center gap-2 text-xl font-black">
                    <Gift
                        size={20}
                        aria-hidden="true"
                        className="text-brand-text"
                    />
                    {t('points.my_points')}
                </h2>
                <div className="text-end">
                    <p
                        className="text-3xl font-black text-brand-text"
                        dir="ltr"
                    >
                        {points.balance.toLocaleString('en')}
                    </p>
                    <p className="text-xs text-ink-soft">
                        {t('points.worth', {
                            value: formatMoney(points.value, 'ILS', 'en'),
                        })}
                    </p>
                </div>
            </div>

            <h3 className="mt-6 text-sm font-bold text-ink-soft">
                {t('points.history')}
            </h3>
            {points.history.length === 0 ? (
                <p className="mt-2 text-sm text-ink-soft">
                    {t('points.no_history')}
                </p>
            ) : (
                <ul className="mt-2 divide-y divide-line text-sm">
                    {points.history.map((row, index) => (
                        <li
                            key={`${row.created_at}-${index}`}
                            className="flex items-center justify-between gap-4 py-2.5"
                        >
                            <span>
                                <span className="font-semibold">
                                    {row.type}
                                </span>
                                {row.reference && (
                                    <span
                                        className="ms-2 text-xs text-ink-soft"
                                        dir="ltr"
                                    >
                                        #{row.reference}
                                    </span>
                                )}
                                {row.created_at && (
                                    <span className="block text-xs text-ink-soft">
                                        {new Date(
                                            row.created_at,
                                        ).toLocaleDateString(locale)}
                                    </span>
                                )}
                            </span>
                            <span
                                dir="ltr"
                                className={
                                    row.points > 0
                                        ? 'font-black text-emerald-700'
                                        : 'font-black text-rose-700'
                                }
                            >
                                {row.points > 0 ? '+' : ''}
                                {row.points}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
