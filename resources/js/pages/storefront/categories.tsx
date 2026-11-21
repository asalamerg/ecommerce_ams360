import { Link, usePage } from '@inertiajs/react';
import Reveal from '@/components/store/reveal';
import SeoTitle from '@/components/store/seo-title';
import { useTranslations } from '@/hooks/use-translations';
import StorefrontLayout from '@/layouts/storefront-layout';
import type { CategoryCard } from '@/types';

export default function StorefrontCategories({
    categories,
}: {
    categories: CategoryCard[];
}) {
    const { locale } = usePage().props;
    const t = useTranslations();

    return (
        <StorefrontLayout>
            <SeoTitle />
            <main className="mx-auto max-w-7xl px-4 py-6 sm:px-6 sm:py-10 lg:px-8">
                <h1 className="mb-6 text-2xl font-black sm:text-3xl">
                    {t('store.all_categories')}
                </h1>
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4 xl:grid-cols-5">
                    {categories.map((category, index) => (
                        <Reveal key={category.slug} delay={(index % 5) * 60}>
                            <Link
                                href={`/${locale}/categories/${category.slug}`}
                                className="group block overflow-hidden rounded-panel border border-line bg-panel transition duration-300 hover:-translate-y-1 hover:border-brand hover:shadow-lg motion-reduce:transform-none"
                            >
                                <span className="block aspect-[4/3] overflow-hidden bg-surface">
                                    {category.image_url && (
                                        <img
                                            src={category.image_url}
                                            alt=""
                                            loading="lazy"
                                            decoding="async"
                                            className="h-full w-full object-contain p-3 transition duration-300 group-hover:scale-105 motion-reduce:transform-none"
                                        />
                                    )}
                                </span>
                                <span className="block p-3">
                                    <span className="line-clamp-2 min-h-10 text-sm font-bold text-ink group-hover:text-brand-text">
                                        {category.name}
                                    </span>
                                    <span className="mt-1 block text-xs text-ink-soft">
                                        {t('store.products_count', {
                                            count: category.count,
                                        })}
                                    </span>
                                </span>
                            </Link>
                        </Reveal>
                    ))}
                </div>
            </main>
        </StorefrontLayout>
    );
}
