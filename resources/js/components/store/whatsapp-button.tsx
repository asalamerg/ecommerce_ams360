import { usePage } from '@inertiajs/react';
import { MessageCircle } from 'lucide-react';
import { useCompare } from '@/hooks/use-compare';
import { useTranslations } from '@/hooks/use-translations';
import { cn } from '@/lib/utils';

/**
 * The WhatsApp link for the current page. On a product page the message names
 * the product and links to it (its canonical address, which also works while
 * the page renders on the server), so staff know at once what it is about.
 */
function useWhatsAppLink(): string | null {
    const { component, props } = usePage();
    const t = useTranslations();
    const whatsapp = props.footer?.whatsapp;

    if (!whatsapp) {
        return null;
    }

    const product =
        component === 'storefront/show'
            ? (props.product as { name: string } | undefined)
            : undefined;
    const text =
        product && props.seo?.canonical
            ? t('service.whatsapp_product', {
                  name: product.name,
                  url: props.seo.canonical,
              })
            : t('store.whatsapp_hello');

    return `${whatsapp}?text=${encodeURIComponent(text)}`;
}

/**
 * Stays in the corner of every store page. On a phone it steps aside where a
 * bar already sits at the bottom (the product page's buy bar, the comparison
 * bar), so it never covers a button; the product page has its own link.
 */
export default function WhatsAppButton() {
    const { component } = usePage();
    const t = useTranslations();
    const href = useWhatsAppLink();
    const comparing = useCompare().length > 0;

    if (!href) {
        return null;
    }

    return (
        <a
            href={href}
            target="_blank"
            rel="noopener noreferrer"
            aria-label={t('service.ask_whatsapp')}
            title={t('service.ask_whatsapp')}
            className={cn(
                'fixed end-4 bottom-[calc(4.75rem+env(safe-area-inset-bottom))] z-40 h-14 w-14 place-items-center rounded-full bg-[#25D366] text-white shadow-xl ring-4 ring-white/60 transition hover:scale-105 hover:bg-[#1ebe5b] motion-reduce:transform-none md:bottom-6 dark:ring-black/30',
                component === 'storefront/show' || comparing
                    ? 'hidden md:grid'
                    : 'grid',
            )}
        >
            <MessageCircle size={28} aria-hidden="true" />
        </a>
    );
}

/** "Ask us on WhatsApp" beside a product, with its name and link. */
export function ProductWhatsAppLink() {
    const t = useTranslations();
    const href = useWhatsAppLink();

    if (!href) {
        return null;
    }

    return (
        <a
            href={href}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex h-10 items-center gap-2 rounded-full border border-[#25D366] px-4 text-sm font-bold text-[#128c4a] transition hover:bg-[#25D366] hover:text-[#0b3d1f] dark:text-[#4ade80]"
        >
            <MessageCircle size={16} aria-hidden="true" />
            {t('service.ask_whatsapp')}
        </a>
    );
}
