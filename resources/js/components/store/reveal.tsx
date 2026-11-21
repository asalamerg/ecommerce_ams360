import type { ReactNode } from 'react';
import { useEffect, useRef } from 'react';
import { cn } from '@/lib/utils';

/**
 * Fades its content in once when it scrolls into view. Uses only transform and
 * opacity, and app.css turns it off for reduced-motion users.
 *
 * Content is visible as rendered (also by the server), so what is on screen when
 * the page opens shows at once, without waiting for JavaScript. Only content
 * still below the fold at that moment is hidden, and fades in when reached.
 */
export default function Reveal({
    children,
    delay = 0,
    className,
}: {
    children: ReactNode;
    delay?: number;
    className?: string;
}) {
    const ref = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const element = ref.current;

        if (
            !element ||
            element.getBoundingClientRect().top < window.innerHeight
        ) {
            return;
        }

        // Set on the element itself, not through state: React never renders the
        // attribute, so the server markup and the first paint stay visible.
        element.dataset.reveal = 'waiting';

        const observer = new IntersectionObserver(
            ([entry]) => {
                if (entry.isIntersecting) {
                    element.dataset.reveal = 'shown';
                    observer.disconnect();
                }
            },
            { rootMargin: '0px 0px -8% 0px' },
        );

        observer.observe(element);

        return () => observer.disconnect();
    }, []);

    return (
        <div
            ref={ref}
            style={{ '--reveal-delay': `${delay}ms` } as React.CSSProperties}
            className={cn('reveal', className)}
        >
            {children}
        </div>
    );
}
