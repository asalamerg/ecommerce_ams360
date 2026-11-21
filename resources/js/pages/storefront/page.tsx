import SeoTitle from '@/components/store/seo-title';
import StorefrontLayout from '@/layouts/storefront-layout';

export default function StorefrontPage({
    page,
}: {
    page: { title: string | null; content: string | null };
}) {
    return (
        <StorefrontLayout>
            <SeoTitle />
            <main className="mx-auto max-w-3xl px-4 py-8 sm:px-6 sm:py-12">
                <h1 className="mb-5 text-3xl font-black">{page.title}</h1>
                <div className="rounded-panel border border-line bg-panel p-5 leading-8 whitespace-pre-line text-ink/80 sm:p-8">
                    {page.content}
                </div>
            </main>
        </StorefrontLayout>
    );
}
