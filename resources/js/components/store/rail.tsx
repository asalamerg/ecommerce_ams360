import { usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';
import { useTranslations } from '@/hooks/use-translations';
import { cn } from '@/lib/utils';

const AUTO_ADVANCE_MS = 5500;

const arrow =
    'absolute top-1/2 z-10 hidden h-11 w-11 -translate-y-1/2 place-items-center rounded-full border border-line bg-panel text-ink shadow-lg transition hover:border-brand hover:text-brand-text md:grid';

/**
 * A horizontal row of cards. Phones swipe it with native scroll snapping and it
 * never moves on its own there, which is what testing on mobile recommends. On
 * desktop it advances every few seconds while in view, pauses on hover or keyboard
 * focus, and stops for good once the visitor uses the arrows.
 */
export default function Rail({
    children,
    label,
    autoAdvance = false,
    className,
}: {
    children: ReactNode;
    label: string;
    autoAdvance?: boolean;
    className?: string;
}) {
    const { direction } = usePage().props;
    const t = useTranslations();
    const track = useRef<HTMLDivElement>(null);
    const paused = useRef(false);
    const stopped = useRef(false);
    const inView = useRef(false);
    const [edges, setEdges] = useState({ start: true, end: false });

    // In RTL scrollLeft is negative, so distance is measured with Math.abs.
    const forward = direction === 'rtl' ? -1 : 1;

    function measure() {
        const element = track.current;

        if (!element) {
            return;
        }

        const position = Math.abs(element.scrollLeft);

        setEdges({
            start: position < 8,
            end: position + element.clientWidth >= element.scrollWidth - 8,
        });
    }

    function step(): number {
        const element = track.current;
        const card = element?.firstElementChild as HTMLElement | null;

        return card ? card.offsetWidth + 16 : 280;
    }

    function move(sign: number) {
        track.current?.scrollBy({
            left: forward * sign * step() * 2,
            behavior: 'smooth',
        });
    }

    useEffect(() => {
        const element = track.current;

        measure();

        if (!element || !autoAdvance) {
            return;
        }

        const canAnimate =
            window.matchMedia('(hover: hover) and (min-width: 1024px)')
                .matches &&
            !window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        if (!canAnimate) {
            return;
        }

        const observer = new IntersectionObserver(
            ([entry]) => {
                inView.current = entry.isIntersecting;
            },
            { threshold: 0.5 },
        );

        observer.observe(element);

        const timer = window.setInterval(() => {
            if (paused.current || stopped.current || !inView.current) {
                return;
            }

            const position = Math.abs(element.scrollLeft);
            const atEnd =
                position + element.clientWidth >= element.scrollWidth - 8;

            element.scrollBy({
                left: atEnd ? -forward * position : forward * step(),
                behavior: 'smooth',
            });
        }, AUTO_ADVANCE_MS);

        return () => {
            observer.disconnect();
            window.clearInterval(timer);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [autoAdvance, forward]);

    const PreviousIcon = direction === 'rtl' ? ChevronRight : ChevronLeft;
    const NextIcon = direction === 'rtl' ? ChevronLeft : ChevronRight;

    return (
        <div
            role="group"
            aria-label={label}
            className={cn('relative', className)}
            onMouseEnter={() => {
                paused.current = true;
            }}
            onMouseLeave={() => {
                paused.current = false;
            }}
            onFocusCapture={() => {
                paused.current = true;
            }}
            onBlurCapture={() => {
                paused.current = false;
            }}
        >
            {!edges.start && (
                <button
                    type="button"
                    aria-label={t('store.previous')}
                    onClick={() => {
                        stopped.current = true;
                        move(-1);
                    }}
                    className={cn(arrow, '-start-3')}
                >
                    <PreviousIcon size={20} aria-hidden="true" />
                </button>
            )}
            <div
                ref={track}
                onScroll={measure}
                className="-mx-4 flex snap-x snap-mandatory scroll-px-4 scrollbar-none gap-4 overflow-x-auto scroll-smooth px-4 pb-2 sm:-mx-0 sm:px-0"
            >
                {children}
            </div>
            {!edges.end && (
                <button
                    type="button"
                    aria-label={t('store.next')}
                    onClick={() => {
                        stopped.current = true;
                        move(1);
                    }}
                    className={cn(arrow, '-end-3')}
                >
                    <NextIcon size={20} aria-hidden="true" />
                </button>
            )}
        </div>
    );
}
