import { createInertiaApp, router } from '@inertiajs/react';
import { initializeTheme } from '@/hooks/use-appearance';
import { layout, title, withApp } from '@/inertia-options';
import { syncSeo } from '@/lib/seo';

void createInertiaApp({
    title,
    layout,
    strictMode: true,
    withApp,
    progress: {
        color: '#4B5563',
    },
});

// Client-side visits never reload <html>, so language and direction follow each page here.
router.on('navigate', (event) => {
    const { locale, direction, seo } = event.detail.page.props;

    document.documentElement.lang = locale;
    document.documentElement.dir = direction;
    syncSeo(seo);
});

// A missing upload shows a neutral placeholder instead of a broken-image icon.
// Error events do not bubble, so this listens in the capture phase.
document.addEventListener(
    'error',
    (event) => {
        const image = event.target;

        if (
            image instanceof HTMLImageElement &&
            !image.dataset.fallback &&
            new URL(image.src, location.href).pathname.startsWith('/storage/')
        ) {
            image.dataset.fallback = 'true';
            image.src = '/images/placeholder.svg';
        }
    },
    true,
);

// This will set light / dark mode on load...
initializeTheme();
