import { usePage } from '@inertiajs/react';

type Translate = (
    text: string,
    replacements?: Record<string, string | number>,
) => string;

/**
 * Translates admin text. English strings are the keys, shared with PHP's __()
 * through lang/{locale}.json, so a missing entry simply stays in English.
 */
export function useAdminText(): Translate {
    const { adminText: texts, locale } = usePage().props;
    // Kept per language by the browser, see HandleInertiaRequests::share().
    const adminText = texts[locale] ?? {};

    return (text, replacements = {}) =>
        Object.entries(replacements).reduce(
            (result, [key, value]) =>
                result.replaceAll(`:${key}`, String(value)),
            adminText[text] ?? text,
        );
}

export function useDateFormatter(): (value: string) => string {
    const { locale } = usePage().props;
    const formatter = new Intl.DateTimeFormat(`${locale}-u-nu-latn`, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

    return (value) => formatter.format(new Date(value));
}
