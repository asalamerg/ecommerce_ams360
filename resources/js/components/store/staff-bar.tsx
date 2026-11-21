import { usePage } from '@inertiajs/react';
import { LayoutDashboard, PencilLine, ShieldCheck } from 'lucide-react';
import { useTranslations } from '@/hooks/use-translations';

type AdminEdit = { type: 'product' | 'category' | 'brand'; url: string };

/**
 * A slim bar above the store, shown only to signed-in staff, on phones as well
 * as computers: the way back to the admin panel, and on a product, category or
 * brand page a link that opens it in the admin for editing
 * (StorefrontController::adminEdit). Plain links: the admin is a separate
 * layout with its own text, so a full page load is the right way in.
 */
export default function StaffBar() {
    const { auth, adminEdit } = usePage<{ adminEdit?: AdminEdit | null }>()
        .props;
    const t = useTranslations();

    if (!auth.user?.is_staff) {
        return null;
    }

    return (
        <div
            data-staff-bar
            className="relative z-50 bg-ink text-xs font-semibold text-panel"
        >
            <div className="mx-auto flex h-10 max-w-7xl items-center gap-2 px-3 sm:px-6 lg:px-8">
                <ShieldCheck
                    size={15}
                    aria-hidden="true"
                    className="shrink-0 text-brand"
                />
                <span className="hidden min-w-0 truncate opacity-80 min-[480px]:inline">
                    {t('nav.staff_mode')}
                </span>
                <div className="ms-auto flex shrink-0 items-center gap-1.5">
                    {adminEdit && (
                        <a
                            href={adminEdit.url}
                            className="inline-flex h-8 items-center gap-1.5 rounded-btn border border-panel/25 px-3 transition hover:border-brand hover:text-brand"
                        >
                            <PencilLine size={14} aria-hidden="true" />
                            {t(`nav.edit_${adminEdit.type}`)}
                        </a>
                    )}
                    <a
                        href="/dashboard"
                        className="inline-flex h-8 items-center gap-1.5 rounded-btn bg-brand px-3 text-on-brand transition hover:bg-brand-hover"
                    >
                        <LayoutDashboard size={14} aria-hidden="true" />
                        {t('nav.admin_panel')}
                    </a>
                </div>
            </div>
        </div>
    );
}
