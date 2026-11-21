import { Link } from '@inertiajs/react';
import { Gift } from 'lucide-react';
import { FieldError } from '@/components/admin/admin-ui';
import { useTranslations } from '@/hooks/use-translations';
import type { LoyaltyInfo } from '@/lib/loyalty';
import { pointsPreview } from '@/lib/loyalty';

/**
 * Loyalty points in the order summary: what this order will earn, and for a
 * signed-in customer, how many points to spend on it.
 */
export default function PointsBox({
    loyalty,
    payable,
    value,
    onChange,
    error,
    money,
}: {
    loyalty: LoyaltyInfo;
    payable: number;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    money: (amount: number) => string;
}) {
    const t = useTranslations();
    const preview = pointsPreview(loyalty, payable, Number(value));
    const balance = loyalty.balance;
    const minimum = Math.max(loyalty.min_redeem_points, 1);

    return (
        <div className="mt-4 rounded-lg border border-brand/30 bg-brand/5 p-3 text-sm">
            <p className="flex items-center gap-2 font-bold">
                <Gift
                    size={16}
                    aria-hidden="true"
                    className="text-brand-text"
                />
                {t('points.title')}
            </p>

            {balance === null ? (
                <p className="mt-1 text-xs leading-5 text-ink-soft">
                    <Link
                        href="/login"
                        className="font-bold text-brand-text hover:underline"
                    >
                        {t('points.login')}
                    </Link>{' '}
                    {t('points.login_to_earn', { points: preview.earn })}
                </p>
            ) : (
                <>
                    <p className="mt-1 text-xs text-ink-soft">
                        {t('points.balance', {
                            points: balance,
                            value: money(balance * Number(loyalty.point_value)),
                        })}
                    </p>

                    {preview.max >= minimum ? (
                        <div className="mt-2 flex gap-2">
                            <input
                                type="number"
                                min={0}
                                max={preview.max}
                                step={1}
                                inputMode="numeric"
                                dir="ltr"
                                value={value}
                                onChange={(event) =>
                                    onChange(event.target.value)
                                }
                                placeholder="0"
                                aria-label={t('points.use')}
                                className="admin-input min-w-0 flex-1"
                            />
                            <button
                                type="button"
                                onClick={() => onChange(String(preview.max))}
                                className="shrink-0 rounded-btn border border-line px-3 text-xs font-bold transition hover:border-brand hover:text-brand-text"
                            >
                                {t('points.use_max', { points: preview.max })}
                            </button>
                        </div>
                    ) : (
                        balance > 0 && (
                            <p className="mt-1 text-xs text-ink-soft">
                                {t('points.cannot_use', {
                                    points: minimum,
                                })}
                            </p>
                        )
                    )}
                    <FieldError message={error} />

                    {preview.earn > 0 && (
                        <p className="mt-2 text-xs font-semibold text-emerald-700">
                            {t('points.will_earn', { points: preview.earn })}
                        </p>
                    )}
                </>
            )}
        </div>
    );
}
