import { Head, usePage } from '@inertiajs/react';

/**
 * The title from the page's server-side SEO data, so the tab shows the same
 * title the first response printed for search engines.
 */
export default function SeoTitle() {
    const { seo } = usePage().props;

    return <Head title={seo?.title ?? ''} />;
}
