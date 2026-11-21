import { Minus, Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslations } from '@/hooks/use-translations';

const button =
    'grid h-9 w-9 place-items-center text-ink transition hover:text-brand-text disabled:cursor-not-allowed disabled:opacity-40';

/**
 * Typing is committed on blur, so clearing the box to retype a number never
 * removes the line.
 */
export default function QuantityStepper({
    value,
    max,
    onChange,
}: {
    value: number;
    max: number;
    onChange: (quantity: number) => void;
}) {
    const t = useTranslations();
    const [text, setText] = useState(String(value));

    useEffect(() => setText(String(value)), [value]);

    function commit() {
        const typed = Number.parseInt(text, 10);

        if (Number.isNaN(typed) || typed < 1) {
            setText(String(value));

            return;
        }

        const quantity = Math.min(typed, max);

        setText(String(quantity));

        if (quantity !== value) {
            onChange(quantity);
        }
    }

    return (
        <div
            dir="ltr"
            className="inline-flex items-center rounded-btn border border-line bg-panel"
        >
            <button
                type="button"
                aria-label="-"
                disabled={value <= 1}
                onClick={() => onChange(value - 1)}
                className={button}
            >
                <Minus size={15} aria-hidden="true" />
            </button>
            <input
                type="text"
                inputMode="numeric"
                aria-label={t('store.quantity')}
                value={text}
                onChange={(event) =>
                    setText(event.target.value.replace(/\D/g, ''))
                }
                onBlur={commit}
                onKeyDown={(event) => {
                    if (event.key === 'Enter') {
                        event.currentTarget.blur();
                    }
                }}
                className="w-9 bg-transparent text-center text-sm font-bold text-ink outline-none"
            />
            <button
                type="button"
                aria-label="+"
                disabled={value >= max}
                onClick={() => onChange(value + 1)}
                className={button}
            >
                <Plus size={15} aria-hidden="true" />
            </button>
        </div>
    );
}
