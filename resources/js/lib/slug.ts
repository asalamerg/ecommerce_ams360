/**
 * What App\Support\Slugs will make from the names when the slug field is left
 * empty, shown as a hint. Only Latin names are previewed here; the server also
 * transliterates Arabic and adds a number when the slug is taken.
 */
export function slugPreview(
    translations: Partial<Record<string, { name: string }>>,
): string {
    for (const locale of ['en', 'ar', 'he']) {
        const slug = (translations[locale]?.name ?? '')
            .normalize('NFKD')
            .replace(/[̀-ͯ]/g, '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');

        if (slug !== '') {
            return slug.slice(0, 170).replace(/-+$/, '');
        }
    }

    return '';
}
