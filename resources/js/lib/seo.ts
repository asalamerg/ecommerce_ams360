/** Built by App\Support\StorefrontSeo. */
export type Seo = {
    title: string;
    description: string | null;
    canonical: string;
    robots: string;
    alternates: Record<string, string>;
    site_name: string;
    type: string;
    image: string | null;
    locale: string;
    schema: Record<string, unknown> | null;
};

function tag(
    name: 'meta' | 'link' | 'script',
    attributes: Record<string, string>,
): HTMLElement {
    const element = document.createElement(name);

    Object.entries(attributes).forEach(([key, value]) =>
        element.setAttribute(key, value),
    );
    element.setAttribute('data-seo', '');

    return element;
}

/**
 * The first response already has these tags from app.blade.php. Client-side
 * visits do not reload <head>, so they are rebuilt here from the new page, the
 * same way the server would print them.
 */
export function syncSeo(seo: Seo | null | undefined): void {
    const tags: HTMLElement[] = [];

    if (!seo) {
        tags.push(tag('meta', { name: 'robots', content: 'noindex' }));
    } else {
        if (seo.description) {
            tags.push(
                tag('meta', { name: 'description', content: seo.description }),
                tag('meta', {
                    property: 'og:description',
                    content: seo.description,
                }),
            );
        }

        tags.push(
            tag('meta', { name: 'robots', content: seo.robots }),
            tag('link', { rel: 'canonical', href: seo.canonical }),
            ...Object.entries(seo.alternates).map(([hreflang, href]) =>
                tag('link', { rel: 'alternate', hreflang, href }),
            ),
            tag('meta', { property: 'og:type', content: seo.type }),
            tag('meta', { property: 'og:site_name', content: seo.site_name }),
            tag('meta', { property: 'og:title', content: seo.title }),
            tag('meta', { property: 'og:url', content: seo.canonical }),
            tag('meta', { property: 'og:locale', content: seo.locale }),
            tag('meta', {
                name: 'twitter:card',
                content: seo.image ? 'summary_large_image' : 'summary',
            }),
        );

        if (seo.image) {
            tags.push(
                tag('meta', { property: 'og:image', content: seo.image }),
            );
        }

        if (seo.schema) {
            const script = tag('script', { type: 'application/ld+json' });

            // textContent, never innerHTML: the data is shown as text, not parsed.
            script.textContent = JSON.stringify(seo.schema);
            tags.push(script);
        }
    }

    document.head
        .querySelectorAll('[data-seo]')
        .forEach((element) => element.remove());
    document.head.append(...tags);
}
