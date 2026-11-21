import { useTranslations } from '@/hooks/use-translations';
import { cn } from '@/lib/utils';
import type { ProductColor } from '@/types';

/** The colors a product comes in, as small dots on its card. */
export function ColorDots({
    colors,
    max = 5,
}: {
    colors: ProductColor[];
    max?: number;
}) {
    const t = useTranslations();

    if (colors.length === 0) {
        return null;
    }

    return (
        <span
            className="flex items-center gap-1"
            aria-label={`${t('store.color')}: ${colors.map((color) => color.name).join('، ')}`}
        >
            {colors.slice(0, max).map((color) => (
                <span
                    key={color.id}
                    title={color.name}
                    className="size-3.5 rounded-full ring-1 ring-black/15 ring-inset"
                    style={{ backgroundColor: color.hex }}
                />
            ))}
            {colors.length > max && (
                <span className="text-[11px] font-bold text-ink-soft">
                    {t('store.colors_more', { count: colors.length - max })}
                </span>
            )}
        </span>
    );
}

/**
 * The color picker on the product page: round swatches with the chosen
 * color's name next to the label.
 */
export function ColorPicker({
    colors,
    value,
    onChange,
    error,
}: {
    colors: ProductColor[];
    value: number | null;
    onChange: (id: number) => void;
    error?: boolean;
}) {
    const t = useTranslations();
    const chosen = colors.find((color) => color.id === value);

    return (
        <fieldset>
            <legend className="text-sm font-bold">
                {t('store.color')}
                {chosen && (
                    <span className="ms-1 font-semibold text-ink-soft">
                        : {chosen.name}
                    </span>
                )}
            </legend>
            <div
                className={cn(
                    'mt-2 flex flex-wrap gap-2.5 rounded-xl p-1',
                    error && 'ring-2 ring-rose-400',
                )}
            >
                {colors.map((color) => (
                    <button
                        key={color.id}
                        type="button"
                        onClick={() => onChange(color.id)}
                        aria-pressed={color.id === value}
                        aria-label={color.name}
                        title={color.name}
                        className={cn(
                            'size-9 rounded-full transition hover:scale-110 motion-reduce:transform-none',
                            color.id === value
                                ? 'scale-110 shadow-[0_0_0_2px_var(--brand-card),0_0_0_4px_var(--brand-primary)]'
                                : 'ring-1 ring-black/15 ring-inset',
                        )}
                        style={{ backgroundColor: color.hex }}
                    />
                ))}
            </div>
            {error && (
                <p className="mt-1 text-xs font-semibold text-rose-700">
                    {t('store.choose_color')}
                </p>
            )}
        </fieldset>
    );
}

/** The color chosen for a cart or order line. */
export function LineColor({ color }: { color: ProductColor | null }) {
    if (!color) {
        return null;
    }

    return (
        <span className="mt-1 inline-flex items-center gap-1.5 text-xs text-ink-soft">
            <span
                className="size-3 rounded-full ring-1 ring-black/15 ring-inset"
                style={{ backgroundColor: color.hex }}
            />
            {color.name}
        </span>
    );
}
