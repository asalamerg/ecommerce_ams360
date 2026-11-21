import { Clock } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslations } from '@/hooks/use-translations';
import { cn } from '@/lib/utils';

function parts(milliseconds: number) {
    const seconds = Math.max(0, Math.floor(milliseconds / 1000));

    return {
        days: Math.floor(seconds / 86400),
        hours: Math.floor((seconds % 86400) / 3600),
        minutes: Math.floor((seconds % 3600) / 60),
        seconds: seconds % 60,
    };
}

const two = (value: number) => String(value).padStart(2, '0');

/**
 * Time left until an offer or discount ends, ticking every second. Drawn only
 * in the browser: the server's clock would not match it (hydration).
 */
export default function Countdown({
    endsAt,
    className,
}: {
    endsAt: string;
    className?: string;
}) {
    const t = useTranslations();
    const end = new Date(endsAt).getTime();
    const [now, setNow] = useState<number | null>(null);

    useEffect(() => {
        setNow(Date.now());
        const timer = window.setInterval(() => setNow(Date.now()), 1000);

        return () => window.clearInterval(timer);
    }, []);

    if (now === null || Number.isNaN(end)) {
        return null;
    }

    if (end <= now) {
        return (
            <span className={cn('text-sm font-bold text-ink-soft', className)}>
                {t('store.offer_ended')}
            </span>
        );
    }

    const left = parts(end - now);

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 text-sm font-bold',
                className,
            )}
            role="timer"
        >
            <Clock size={15} aria-hidden="true" />
            {t('store.ends_in')}
            {/* The days in the page's own direction, the clock always left to right. */}
            {left.days > 0 && (
                <span>{t('store.days', { count: left.days })}</span>
            )}
            <span dir="ltr" className="tabular-nums">
                {two(left.hours)}:{two(left.minutes)}:{two(left.seconds)}
            </span>
        </span>
    );
}
