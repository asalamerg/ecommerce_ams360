import { createInertiaApp, router } from "@inertiajs/react";
import { initializeTheme } from "@/hooks/use-appearance";
import { layout, setStoreName, title, withApp } from "@/inertia-options";
import { syncSeo } from "@/lib/seo";

// The first page's props are in the HTML; titles use the shop's own name.
try {
    const source =
        document.querySelector<HTMLScriptElement>("script[data-page]")
            ?.textContent ?? document.getElementById("app")?.dataset.page;
    setStoreName(source ? JSON.parse(source)?.props?.store?.name : null);
} catch {
    // Keep the build-time name.
}

const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)");

void createInertiaApp({
    title,
    layout,
    defaults: {
        // Moving to another page cross-fades where the browser can (View
        // Transitions), with the header held still. Form posts, filters and
        // partial reloads change the page in place and stay instant.
        visitOptions: (_href, options) =>
            options.method === "get" &&
            !options.preserveState &&
            !options.only?.length &&
            !reducedMotion.matches
                ? { ...options, viewTransition: true }
                : options,
    },
    strictMode: true,
    withApp,
    progress: {
        color: "#4B5563",
    },
});

// Client-side visits never reload <html>, so language and direction follow each page here.
router.on("navigate", (event) => {
    const { locale, direction, seo, store } = event.detail.page.props;

    setStoreName(store?.name);

    document.documentElement.lang = locale;
    document.documentElement.dir = direction;
    syncSeo(seo);
});

// A missing upload shows a neutral placeholder instead of a broken-image icon.
// Error events do not bubble, so this listens in the capture phase.
document.addEventListener(
    "error",
    (event) => {
        const image = event.target;

        if (
            image instanceof HTMLImageElement &&
            !image.dataset.fallback &&
            new URL(image.src, location.href).pathname.startsWith("/storage/")
        ) {
            image.dataset.fallback = "true";
            image.src = "/images/placeholder.svg";
        }
    },
    true,
);

// This will set light / dark mode on load...
initializeTheme();
