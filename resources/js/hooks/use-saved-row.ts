import { usePage } from '@inertiajs/react';
import { useCallback } from 'react';

/**
 * Lets an admin list hand its own address to the add and edit forms, so a
 * save comes back to the same page, search and filter, and marks the row
 * just saved. See CatalogController::afterSave().
 */
export function useSavedRow() {
    const { url, props } = usePage();
    const highlight = props.flash?.highlight ?? null;

    const withBack = useCallback(
        (href: string) =>
            `${href}${href.includes('?') ? '&' : '?'}back=${encodeURIComponent(url)}`,
        [url],
    );

    // Brings the saved row into view once, when it is drawn.
    const savedRowRef = useCallback((element: HTMLElement | null) => {
        element?.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }, []);

    return { withBack, highlight, savedRowRef };
}

export const savedRowClass =
    'bg-emerald-50 ring-2 ring-emerald-400 ring-inset dark:bg-emerald-950/30';
