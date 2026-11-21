import { useForm, usePage } from '@inertiajs/react';
import { BellRing, Star } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import Turnstile from '@/components/store/turnstile';
import { useTranslations } from '@/hooks/use-translations';
import { cn } from '@/lib/utils';

export type ReviewsData = {
    count: number;
    average: number;
    items: {
        name: string;
        rating: number;
        comment: string | null;
        date: string;
    }[];
    can_review: boolean;
    mine: {
        rating: number;
        comment: string | null;
        is_approved: boolean;
    } | null;
};

export function Stars({ value, size = 16 }: { value: number; size?: number }) {
    return (
        <span className="inline-flex" aria-hidden="true">
            {[1, 2, 3, 4, 5].map((star) => (
                <Star
                    key={star}
                    size={size}
                    className={cn(
                        star <= Math.round(value)
                            ? 'fill-amber-400 text-amber-400'
                            : 'text-line',
                    )}
                />
            ))}
        </span>
    );
}

export function ProductReviews({
    productId,
    reviews,
}: {
    productId: number;
    reviews: ReviewsData;
}) {
    const { locale } = usePage().props;
    const t = useTranslations();
    const form = useForm({
        rating: reviews.mine?.rating ?? 5,
        comment: reviews.mine?.comment ?? '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(`/${locale}/products/${productId}/reviews`, {
            preserveScroll: true,
        });
    }

    return (
        <section aria-labelledby="reviews" className="mt-10">
            <h2 id="reviews" className="text-xl font-black">
                {t('reviews.title')}
            </h2>
            {reviews.count > 0 ? (
                <p className="mt-1 flex items-center gap-2 text-sm text-ink-soft">
                    <Stars value={reviews.average} />
                    <span>
                        {t('reviews.summary', {
                            average: reviews.average,
                            count: reviews.count,
                        })}
                    </span>
                </p>
            ) : (
                <p className="mt-1 text-sm text-ink-soft">
                    {t('reviews.none')}
                </p>
            )}

            {reviews.items.length > 0 && (
                <ul className="mt-4 grid gap-3 md:grid-cols-2">
                    {reviews.items.map((review, index) => (
                        <li
                            key={index}
                            className="rounded-panel border border-line bg-panel p-4"
                        >
                            <div className="flex items-center justify-between gap-2">
                                <span className="font-bold">{review.name}</span>
                                <Stars value={review.rating} size={14} />
                            </div>
                            {review.comment && (
                                <p className="mt-2 text-sm leading-6 whitespace-pre-line text-ink-soft">
                                    {review.comment}
                                </p>
                            )}
                            <p className="mt-2 text-xs text-ink-soft" dir="ltr">
                                {review.date}
                            </p>
                        </li>
                    ))}
                </ul>
            )}

            {reviews.can_review && (
                <form
                    onSubmit={submit}
                    className="mt-5 grid max-w-xl gap-3 rounded-panel border border-line bg-panel p-4"
                >
                    <p className="font-bold">
                        {reviews.mine
                            ? t('reviews.edit_yours')
                            : t('reviews.write')}
                    </p>
                    {reviews.mine && !reviews.mine.is_approved && (
                        <p className="text-xs text-amber-800">
                            {t('reviews.pending')}
                        </p>
                    )}
                    <div
                        role="radiogroup"
                        aria-label={t('reviews.rating')}
                        className="flex gap-1"
                    >
                        {[1, 2, 3, 4, 5].map((star) => (
                            <button
                                key={star}
                                type="button"
                                role="radio"
                                aria-checked={form.data.rating === star}
                                aria-label={t('reviews.stars', { count: star })}
                                onClick={() => form.setData('rating', star)}
                            >
                                <Star
                                    size={26}
                                    aria-hidden="true"
                                    className={cn(
                                        star <= form.data.rating
                                            ? 'fill-amber-400 text-amber-400'
                                            : 'text-line',
                                    )}
                                />
                            </button>
                        ))}
                    </div>
                    <textarea
                        rows={3}
                        maxLength={1000}
                        value={form.data.comment}
                        onChange={(event) =>
                            form.setData('comment', event.target.value)
                        }
                        placeholder={t('reviews.comment')}
                        className="admin-input"
                    />
                    {form.errors.comment && (
                        <p className="text-sm text-rose-700">
                            {form.errors.comment}
                        </p>
                    )}
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="h-11 rounded-btn bg-brand font-bold text-on-brand transition hover:bg-brand-hover disabled:opacity-60"
                    >
                        {t('reviews.send')}
                    </button>
                </form>
            )}
        </section>
    );
}

export function NotifyWhenAvailable({
    productId,
    turnstileSiteKey,
}: {
    productId: number;
    turnstileSiteKey: string | null;
}) {
    const { locale, auth, flash } = usePage().props;
    const t = useTranslations();
    const [turnstileKey, setTurnstileKey] = useState(0);
    const form = useForm({
        email: auth.user?.email ?? '',
        turnstile_token: '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(`/${locale}/products/${productId}/notify`, {
            preserveScroll: true,
            // A token is good for one try only.
            onError: () => {
                if (turnstileSiteKey) {
                    form.setData('turnstile_token', '');
                    setTurnstileKey((key) => key + 1);
                }
            },
        });
    }

    if (form.wasSuccessful && flash.success) {
        return (
            <p className="mt-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800">
                {flash.success}
            </p>
        );
    }

    return (
        <form
            onSubmit={submit}
            className="mt-4 rounded-panel border border-line bg-surface p-4"
        >
            <p className="flex items-center gap-2 text-sm font-bold">
                <BellRing size={17} aria-hidden="true" />
                {t('stock_alerts.prompt')}
            </p>
            <div className="mt-2 flex gap-2">
                <input
                    required
                    type="email"
                    dir="ltr"
                    autoComplete="email"
                    value={form.data.email}
                    onChange={(event) =>
                        form.setData('email', event.target.value)
                    }
                    aria-label={t('stock_alerts.email')}
                    placeholder={t('stock_alerts.email')}
                    className="admin-input min-w-0 flex-1"
                />
                <button
                    type="submit"
                    disabled={form.processing}
                    className="shrink-0 rounded-btn bg-brand px-4 text-sm font-bold text-on-brand disabled:opacity-60"
                >
                    {t('stock_alerts.submit')}
                </button>
            </div>
            {turnstileSiteKey && (
                <div className="mt-3">
                    <Turnstile
                        key={turnstileKey}
                        siteKey={turnstileSiteKey}
                        locale={locale}
                        onToken={(token) =>
                            form.setData('turnstile_token', token)
                        }
                    />
                </div>
            )}
            {(form.errors.email || form.errors.turnstile_token) && (
                <p className="mt-1 text-sm text-rose-700">
                    {form.errors.email || form.errors.turnstile_token}
                </p>
            )}
        </form>
    );
}
