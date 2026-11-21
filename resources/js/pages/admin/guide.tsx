import { PageHeader, panel } from '@/components/admin/admin-ui';
import { useAdminText } from '@/hooks/use-admin-text';
import StoreAdminLayout from '@/layouts/store-admin-layout';

const sections = [
    {
        title: 'Catalog and promotions',
        body: 'Manage products, categories, brands, campaign slides, offers, and discount codes. Imported catalog prices are estimates until confirmed.',
    },
    {
        title: 'Inventory and orders',
        body: 'Enter verified stock adjustments through Inventory. Review order details and update order/payment statuses only after confirming the corresponding operation.',
    },
    {
        title: 'Customer service',
        body: 'Review repair requests, support tickets, contact messages, and product reviews. Publish reviews only after moderation.',
    },
    {
        title: 'Store appearance',
        body: 'Every change in the theme editor stays a private draft until you publish it. Preview it on the site first, and restore an earlier theme from the publish history if needed.',
    },
    {
        title: 'Staff access',
        body: 'Grant only the role required for a staff member. Only the store owner can assign or change the owner role.',
    },
    {
        title: 'Payments',
        body: 'Online payment processing is not enabled. Do not mark an order paid unless payment has been independently confirmed.',
    },
];

export default function StoreAdminGuide() {
    const t = useAdminText();

    return (
        <StoreAdminLayout title={t('Admin guide')}>
            <PageHeader
                eyebrow={t('Help center')}
                title={t('Admin guide')}
                description={t('Safe operating practices for the store team')}
            />
            <div className="grid gap-4 md:grid-cols-2">
                {sections.map((section) => (
                    <section key={section.title} className={`${panel} p-5`}>
                        <h2 className="font-extrabold">{t(section.title)}</h2>
                        <p className="mt-2 text-sm leading-7 text-ink-soft">
                            {t(section.body)}
                        </p>
                    </section>
                ))}
            </div>
        </StoreAdminLayout>
    );
}
