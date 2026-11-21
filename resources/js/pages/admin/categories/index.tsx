import { Link, usePage } from '@inertiajs/react';
import { House, Plus, Search, Tags } from 'lucide-react';
import {
    EmptyState,
    PageHeader,
    Pagination,
    SearchBox,
    panel,
    primaryButton,
} from '@/components/admin/admin-ui';
import {
    DeleteButton,
    VisibilityToggle,
} from '@/components/admin/catalog-actions';
import { useAdminText } from '@/hooks/use-admin-text';
import { savedRowClass, useSavedRow } from '@/hooks/use-saved-row';
import StoreAdminLayout from '@/layouts/store-admin-layout';
import { cn } from '@/lib/utils';
import type { PageLink } from '@/types';

type Translation = { name: string; slug: string };
type Category = {
    id: number;
    is_active: boolean;
    show_on_home: boolean;
    position: number;
    image_url: string | null;
    translations: Record<string, Translation>;
    parent: Record<string, Translation> | null;
    children_count: number;
};

export default function CategoriesIndex({
    categories,
    search,
}: {
    categories: { data: Category[]; links: PageLink[]; total: number };
    search: string;
}) {
    const { locale, errors } = usePage().props as ReturnType<
        typeof usePage
    >['props'] & { errors: Record<string, string> };
    const t = useAdminText();
    const { withBack, highlight, savedRowRef } = useSavedRow();

    return (
        <StoreAdminLayout title={t('Categories')}>
            <PageHeader
                eyebrow={t('Catalog')}
                title={t('Categories')}
                description={t(':count categories', {
                    count: categories.total,
                })}
            >
                <div className="flex w-full flex-wrap items-center gap-3 sm:w-auto">
                    <SearchBox value={search} url="/admin/categories" />
                    <Link
                        href={withBack('/admin/categories/create')}
                        className={primaryButton}
                    >
                        <Plus size={18} aria-hidden="true" />
                        {t('Add category')}
                    </Link>
                </div>
            </PageHeader>
            {errors.category && (
                <p
                    role="alert"
                    className="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800"
                >
                    {errors.category}
                </p>
            )}
            <div className={`${panel} overflow-hidden`}>
                {categories.data.length ? (
                    <div className="divide-y divide-line">
                        {categories.data.map((category) => (
                            <div
                                key={category.id}
                                ref={
                                    highlight === category.id
                                        ? savedRowRef
                                        : undefined
                                }
                                className={cn(
                                    'flex flex-wrap items-center justify-between gap-4 px-5 py-3 transition hover:bg-surface/60',
                                    highlight === category.id && savedRowClass,
                                )}
                            >
                                <div className="flex min-w-0 items-center gap-4">
                                    {category.image_url && (
                                        <img
                                            src={category.image_url}
                                            alt=""
                                            loading="lazy"
                                            decoding="async"
                                            className="h-12 w-12 rounded-xl bg-surface object-contain p-1"
                                        />
                                    )}
                                    <div className="min-w-0">
                                        <p className="font-bold">
                                            {category.translations[locale]
                                                ?.name ??
                                                category.translations.ar
                                                    ?.name ??
                                                category.translations.en?.name}
                                        </p>
                                        <p className="mt-0.5 text-xs text-ink-soft">
                                            {t('Position :position', {
                                                position: category.position,
                                            })}
                                            {category.parent &&
                                                ` · ${t('Under :parent', {
                                                    parent:
                                                        category.parent[locale]
                                                            ?.name ??
                                                        category.parent.ar
                                                            ?.name ??
                                                        '',
                                                })}`}
                                            {category.children_count > 0 &&
                                                ` · ${t(
                                                    'Main section with :count categories',
                                                    {
                                                        count: category.children_count,
                                                    },
                                                )}`}
                                            {category.show_on_home && (
                                                <span className="ms-2 inline-flex items-center gap-1 rounded-full bg-brand/10 px-2 py-0.5 font-bold text-brand-text">
                                                    <House
                                                        size={12}
                                                        aria-hidden="true"
                                                    />
                                                    {t('On the home page')}
                                                </span>
                                            )}
                                        </p>
                                    </div>
                                </div>
                                <div className="flex items-center gap-4">
                                    <VisibilityToggle
                                        visible={category.is_active}
                                        url={`/admin/categories/${category.id}/visibility`}
                                    />
                                    <Link
                                        href={withBack(
                                            `/admin/categories/${category.id}/edit`,
                                        )}
                                        className="text-sm font-bold text-brand-text hover:underline"
                                    >
                                        {t('Edit')}
                                    </Link>
                                    <span className="text-sm">
                                        <DeleteButton
                                            url={`/admin/categories/${category.id}`}
                                            confirmText={t(
                                                'Delete this category permanently? This cannot be undone.',
                                            )}
                                        />
                                    </span>
                                </div>
                            </div>
                        ))}
                    </div>
                ) : search ? (
                    <EmptyState
                        icon={<Search aria-hidden="true" />}
                        title={t('No results found.')}
                    />
                ) : (
                    <EmptyState
                        icon={<Tags aria-hidden="true" />}
                        title={t('Organize your products')}
                        description={t(
                            'Create categories in all three store languages.',
                        )}
                        action={
                            <Link
                                href={withBack('/admin/categories/create')}
                                className={primaryButton}
                            >
                                {t('Add first category')}
                            </Link>
                        }
                    />
                )}
            </div>
            <Pagination links={categories.links} />
        </StoreAdminLayout>
    );
}
