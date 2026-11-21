import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslations } from '@/hooks/use-translations';
import { cn } from '@/lib/utils';

export type BrandCard = {
    slug: string;
    name: string;
    keywords: string;
    logo_url: string | null;
    image_url: string | null;
    count: number;
};

/**
 * One brand: its logo on a light plate (logos are drawn for light
 * backgrounds, also in dark mode), or its newest product photo when no logo
 * was uploaded. The name is always written under it.
 */
export default function BrandTile({
    brand,
    onClick,
    compact = false,
}: {
    brand: BrandCard;
    onClick?: () => void;
    compact?: boolean;
}) {
    const { locale } = usePage().props;
    const t = useTranslations();
    // Logo first, then the product photo if the logo cannot load, then the initial.
    const pictures = [brand.logo_url, brand.image_url].filter(
        (url): url is string => !!url,
    );
    const [attempt, setAttempt] = useState(0);
    const picture = pictures[attempt] ?? null;
    const isLogo = picture !== null && picture === brand.logo_url;

    return (
        <Link
            href={`/${locale}/brands/${brand.slug}`}
            onClick={onClick}
            prefetch="hover"
            className="group flex flex-col overflow-hidden rounded-panel border border-line bg-panel shadow-sm transition hover:-translate-y-0.5 hover:border-brand hover:shadow-md focus-visible:border-brand motion-reduce:transform-none"
        >
            <span
                className={cn(
                    'relative grid aspect-[4/3] place-items-center overflow-hidden',
                    isLogo ? 'bg-white' : 'bg-surface',
                )}
            >
                {picture ? (
                    <img
                        key={picture}
                        src={picture}
                        alt=""
                        loading="lazy"
                        decoding="async"
                        onError={() => setAttempt((value) => value + 1)}
                        className={cn(
                            'object-contain transition duration-300 group-hover:scale-105 motion-reduce:transform-none',
                            // A logo sits in the same middle area on every card,
                            // whatever its shape; a product photo fills the card.
                            isLogo
                                ? 'max-h-[62%] max-w-[78%]'
                                : cn(
                                      'h-full w-full',
                                      compact ? 'p-2.5' : 'p-4',
                                  ),
                        )}
                    />
                ) : (
                    <span
                        aria-hidden="true"
                        className="text-3xl font-black text-brand-text"
                    >
                        {brand.name.charAt(0)}
                    </span>
                )}
            </span>
            <span className={cn('grid gap-0.5', compact ? 'p-2' : 'p-3')}>
                <span
                    className={cn(
                        'truncate font-bold text-ink group-hover:text-brand-text',
                        compact ? 'text-xs' : 'text-sm',
                    )}
                >
                    {brand.name}
                </span>
                <span className="text-[11px] text-ink-soft">
                    {t('brands.count', { count: brand.count })}
                </span>
            </span>
        </Link>
    );
}
