import { usePage } from '@inertiajs/react';

/**
 * Looks up server-provided interface text (lang/{locale}/ui.php) by dotted key.
 */
export function useTranslations(): (
    key: string,
    replacements?: Record<string, string | number>,
) => string {
    const { ui: texts, locale } = usePage().props;
    // Kept per language by the browser, see HandleInertiaRequests::share().
    const ui = texts[locale];

    return (key, replacements = {}) => {
        const value = key
            .split('.')
            .reduce<unknown>(
                (node, part) =>
                    typeof node === 'object' && node !== null
                        ? (node as Record<string, unknown>)[part]
                        : undefined,
                ui,
            );

        return typeof value === 'string'
            ? Object.entries(replacements).reduce(
                  (text, [name, replacement]) =>
                      text.replaceAll(`:${name}`, String(replacement)),
                  value,
              )
            : key;
    };
}
