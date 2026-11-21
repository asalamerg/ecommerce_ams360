import { useEffect, useRef } from 'react';

type TurnstileApi = {
    render: (
        element: HTMLElement,
        options: {
            sitekey: string;
            language?: string;
            callback: (token: string) => void;
            'expired-callback': () => void;
            'error-callback': () => void;
        },
    ) => string;
    remove: (widgetId: string) => void;
};

declare global {
    interface Window {
        turnstile?: TurnstileApi;
    }
}

const SCRIPT_URL =
    'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

let scriptLoading: Promise<TurnstileApi> | null = null;

function loadTurnstile(): Promise<TurnstileApi> {
    if (window.turnstile) {
        return Promise.resolve(window.turnstile);
    }

    scriptLoading ??= new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = SCRIPT_URL;
        script.async = true;
        script.onload = () =>
            window.turnstile
                ? resolve(window.turnstile)
                : reject(new Error('Turnstile did not load'));
        script.onerror = () => {
            scriptLoading = null;
            reject(new Error('Turnstile did not load'));
        };
        document.head.appendChild(script);
    });

    return scriptLoading;
}

/**
 * Cloudflare's bot check for checkout. Tokens work once, so remount it (change
 * its key) after every failed submission to get a fresh one.
 */
export default function Turnstile({
    siteKey,
    locale,
    onToken,
}: {
    siteKey: string;
    locale: string;
    onToken: (token: string) => void;
}) {
    const container = useRef<HTMLDivElement>(null);
    const callback = useRef(onToken);

    useEffect(() => {
        callback.current = onToken;
    });

    useEffect(() => {
        let widgetId: string | null = null;
        let cancelled = false;

        loadTurnstile()
            .then((turnstile) => {
                if (cancelled || !container.current) {
                    return;
                }

                widgetId = turnstile.render(container.current, {
                    sitekey: siteKey,
                    language: locale,
                    callback: (token) => callback.current(token),
                    'expired-callback': () => callback.current(''),
                    'error-callback': () => callback.current(''),
                });
            })
            .catch(() => callback.current(''));

        return () => {
            cancelled = true;

            if (widgetId !== null) {
                window.turnstile?.remove(widgetId);
            }
        };
    }, [siteKey, locale]);

    return <div ref={container} className="mt-4 flex justify-center" />;
}
