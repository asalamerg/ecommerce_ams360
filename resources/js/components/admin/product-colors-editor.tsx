import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react';
import { panel } from '@/components/admin/admin-ui';
import { useAdminText } from '@/hooks/use-admin-text';
import { cn } from '@/lib/utils';

export type ColorRow = {
    key: string;
    id?: number;
    hex: string;
    name: { ar: string; en: string; he: string };
};

export const MAX_PRODUCT_COLORS = 20;

/** Common colors with their names in all three languages, added in one click. */
const PRESETS: { hex: string; name: ColorRow['name'] }[] = [
    { hex: '#111111', name: { ar: 'أسود', en: 'Black', he: 'שחור' } },
    { hex: '#FFFFFF', name: { ar: 'أبيض', en: 'White', he: 'לבן' } },
    { hex: '#9CA3AF', name: { ar: 'رمادي', en: 'Gray', he: 'אפור' } },
    { hex: '#C0C0C0', name: { ar: 'فضي', en: 'Silver', he: 'כסוף' } },
    { hex: '#D4AF37', name: { ar: 'ذهبي', en: 'Gold', he: 'זהב' } },
    { hex: '#2563EB', name: { ar: 'أزرق', en: 'Blue', he: 'כחול' } },
    { hex: '#1E3A8A', name: { ar: 'كحلي', en: 'Navy', he: 'כחול כהה' } },
    { hex: '#DC2626', name: { ar: 'أحمر', en: 'Red', he: 'אדום' } },
    { hex: '#EC4899', name: { ar: 'وردي', en: 'Pink', he: 'ורוד' } },
    { hex: '#7C3AED', name: { ar: 'بنفسجي', en: 'Purple', he: 'סגול' } },
    { hex: '#16A34A', name: { ar: 'أخضر', en: 'Green', he: 'ירוק' } },
    { hex: '#F97316', name: { ar: 'برتقالي', en: 'Orange', he: 'כתום' } },
    { hex: '#FACC15', name: { ar: 'أصفر', en: 'Yellow', he: 'צהוב' } },
    { hex: '#E8D9C0', name: { ar: 'بيج', en: 'Beige', he: "בז'" } },
];

let counter = 0;
const newKey = () => `new-${++counter}`;

/**
 * The colors a product comes in. The customer picks one on the product page.
 * Only the Arabic name is required; empty English and Hebrew names use it.
 */
export default function ProductColorsEditor({
    rows,
    onChange,
    errors,
}: {
    rows: ColorRow[];
    onChange: (rows: ColorRow[]) => void;
    errors: Record<string, string>;
}) {
    const t = useAdminText();
    const used = new Set(rows.map((row) => row.hex.toUpperCase()));
    const full = rows.length >= MAX_PRODUCT_COLORS;

    function update(index: number, change: Partial<ColorRow>) {
        onChange(
            rows.map((row, at) => (at === index ? { ...row, ...change } : row)),
        );
    }

    function move(index: number, by: number) {
        const next = [...rows];
        const [row] = next.splice(index, 1);
        next.splice(index + by, 0, row);
        onChange(next);
    }

    return (
        <section className={`${panel} p-5 sm:p-6`}>
            <h2 className="text-lg font-extrabold">{t('Available colors')}</h2>
            <p className="mt-1 text-sm text-ink-soft">
                {t(
                    'Leave empty if the product has one look. With colors, the customer must pick one before adding it to the cart. All colors share the product stock.',
                )}
            </p>

            <div className="mt-4 flex flex-wrap gap-2">
                {PRESETS.map((preset) => {
                    const taken = used.has(preset.hex);

                    return (
                        <button
                            key={preset.hex}
                            type="button"
                            disabled={taken || full}
                            onClick={() =>
                                onChange([
                                    ...rows,
                                    { key: newKey(), ...preset },
                                ])
                            }
                            className="inline-flex items-center gap-1.5 rounded-full border border-line px-2.5 py-1 text-xs font-semibold transition hover:border-brand disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            <span
                                className="size-3.5 rounded-full ring-1 ring-black/15 ring-inset"
                                style={{ backgroundColor: preset.hex }}
                            />
                            {preset.name.ar}
                        </button>
                    );
                })}
                <button
                    type="button"
                    disabled={full}
                    onClick={() =>
                        onChange([
                            ...rows,
                            {
                                key: newKey(),
                                hex: '#888888',
                                name: { ar: '', en: '', he: '' },
                            },
                        ])
                    }
                    className="inline-flex items-center gap-1 rounded-full border border-dashed border-brand px-2.5 py-1 text-xs font-bold text-brand-text transition hover:bg-brand/10 disabled:opacity-40"
                >
                    <Plus size={13} aria-hidden="true" />
                    {t('Custom color')}
                </button>
            </div>

            {rows.length > 0 && (
                <ul className="mt-4 divide-y divide-line rounded-xl border border-line">
                    {rows.map((row, index) => {
                        const error =
                            errors[`colors.${index}.name.ar`] ??
                            errors[`colors.${index}.hex`];

                        return (
                            <li
                                key={row.key}
                                className="flex flex-wrap items-center gap-3 p-3"
                            >
                                <input
                                    type="color"
                                    value={row.hex}
                                    onChange={(event) =>
                                        update(index, {
                                            hex: event.target.value.toUpperCase(),
                                        })
                                    }
                                    aria-label={t('Color')}
                                    className="size-10 shrink-0 cursor-pointer rounded-lg border border-line bg-transparent p-0.5"
                                />
                                <input
                                    value={row.name.ar}
                                    onChange={(event) =>
                                        update(index, {
                                            name: {
                                                ...row.name,
                                                ar: event.target.value,
                                            },
                                        })
                                    }
                                    required
                                    maxLength={40}
                                    placeholder={t('Name in Arabic')}
                                    aria-label={t('Name in Arabic')}
                                    className={cn(
                                        'admin-input w-36 flex-1',
                                        error && 'border-rose-400',
                                    )}
                                />
                                <input
                                    value={row.name.en}
                                    onChange={(event) =>
                                        update(index, {
                                            name: {
                                                ...row.name,
                                                en: event.target.value,
                                            },
                                        })
                                    }
                                    maxLength={40}
                                    dir="ltr"
                                    placeholder="English"
                                    aria-label={t('Name in English')}
                                    className="admin-input hidden w-28 sm:block"
                                />
                                <input
                                    value={row.name.he}
                                    onChange={(event) =>
                                        update(index, {
                                            name: {
                                                ...row.name,
                                                he: event.target.value,
                                            },
                                        })
                                    }
                                    maxLength={40}
                                    placeholder="עברית"
                                    aria-label={t('Name in Hebrew')}
                                    className="admin-input hidden w-28 sm:block"
                                />
                                <span className="flex items-center gap-1">
                                    <button
                                        type="button"
                                        disabled={index === 0}
                                        onClick={() => move(index, -1)}
                                        aria-label={t('Move up')}
                                        className="grid size-8 place-items-center rounded-lg text-ink-soft hover:bg-surface disabled:opacity-30"
                                    >
                                        <ArrowUp size={15} aria-hidden="true" />
                                    </button>
                                    <button
                                        type="button"
                                        disabled={index === rows.length - 1}
                                        onClick={() => move(index, 1)}
                                        aria-label={t('Move down')}
                                        className="grid size-8 place-items-center rounded-lg text-ink-soft hover:bg-surface disabled:opacity-30"
                                    >
                                        <ArrowDown
                                            size={15}
                                            aria-hidden="true"
                                        />
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            onChange(
                                                rows.filter(
                                                    (_, at) => at !== index,
                                                ),
                                            )
                                        }
                                        aria-label={t('Remove color')}
                                        className="grid size-8 place-items-center rounded-lg text-rose-700 hover:bg-rose-50"
                                    >
                                        <Trash2 size={15} aria-hidden="true" />
                                    </button>
                                </span>
                                {error && (
                                    <p className="w-full text-xs text-rose-700">
                                        {error}
                                    </p>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
        </section>
    );
}
