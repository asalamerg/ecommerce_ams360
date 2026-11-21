import { Check, ChevronsUpDown, Search } from 'lucide-react';
import type { KeyboardEvent } from 'react';
import { useEffect, useId, useRef, useState } from 'react';
import { useAdminText } from '@/hooks/use-admin-text';
import { fold } from '@/lib/fold-text';
import { cn } from '@/lib/utils';

export type SelectOption = { value: string; label: string; hint?: string };

/**
 * A dropdown with a search box, for lists too long to scroll (69 categories,
 * 40 brands). Matches any language of the option, ignoring Arabic spelling
 * variants. Works with the keyboard: arrows, Enter and Escape.
 */
export default function SearchableSelect({
    value,
    options,
    onChange,
    emptyLabel,
    placeholder,
}: {
    value: string;
    options: SelectOption[];
    onChange: (value: string) => void;
    emptyLabel: string;
    placeholder?: string;
}) {
    const t = useAdminText();
    const listId = useId();
    const root = useRef<HTMLDivElement>(null);
    const search = useRef<HTMLInputElement>(null);
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);

    const all: SelectOption[] = [{ value: '', label: emptyLabel }, ...options];
    const term = fold(query.trim());
    const shown = term
        ? all.filter((option) =>
              fold(`${option.label} ${option.hint ?? ''}`).includes(term),
          )
        : all;
    const selected = all.find((option) => option.value === value);

    useEffect(() => {
        if (!open) {
            return;
        }

        search.current?.focus();

        function close(event: MouseEvent) {
            if (!root.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        }

        document.addEventListener('mousedown', close);

        return () => document.removeEventListener('mousedown', close);
    }, [open]);

    function choose(option: SelectOption | undefined) {
        if (option) {
            onChange(option.value);
        }

        setOpen(false);
        setQuery('');
    }

    function keys(event: KeyboardEvent<HTMLInputElement>) {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActive((index) => Math.min(index + 1, shown.length - 1));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive((index) => Math.max(index - 1, 0));
        } else if (event.key === 'Enter') {
            event.preventDefault();
            choose(shown[active]);
        } else if (event.key === 'Escape') {
            setOpen(false);
        }
    }

    return (
        <div ref={root} className="relative">
            <button
                type="button"
                onClick={() => {
                    setOpen((isOpen) => !isOpen);
                    setActive(0);
                }}
                aria-haspopup="listbox"
                aria-expanded={open}
                className="admin-input flex w-full items-center justify-between gap-2 text-start font-normal"
            >
                <span
                    className={cn(
                        'truncate',
                        !selected?.value && 'text-ink-soft',
                    )}
                >
                    {selected?.label ?? emptyLabel}
                </span>
                <ChevronsUpDown
                    size={15}
                    aria-hidden="true"
                    className="shrink-0 text-ink-soft"
                />
            </button>

            {open && (
                <div className="absolute inset-x-0 top-full z-30 mt-1 overflow-hidden rounded-xl border border-line bg-panel shadow-xl">
                    <div className="relative border-b border-line p-2">
                        <Search
                            size={15}
                            aria-hidden="true"
                            className="pointer-events-none absolute start-4 top-1/2 -translate-y-1/2 text-ink-soft"
                        />
                        <input
                            ref={search}
                            type="search"
                            value={query}
                            onChange={(event) => {
                                setQuery(event.target.value);
                                setActive(0);
                            }}
                            onKeyDown={keys}
                            placeholder={placeholder ?? t('Search…')}
                            aria-controls={listId}
                            className="w-full rounded-lg bg-surface py-2 ps-8 pe-3 text-sm font-normal outline-none"
                        />
                    </div>
                    <ul
                        id={listId}
                        role="listbox"
                        className="max-h-64 overflow-y-auto py-1"
                    >
                        {shown.map((option, index) => (
                            <li
                                key={option.value || 'none'}
                                role="option"
                                aria-selected={option.value === value}
                                onMouseEnter={() => setActive(index)}
                                onMouseDown={(event) => {
                                    event.preventDefault();
                                    choose(option);
                                }}
                                className={cn(
                                    'flex cursor-pointer items-center justify-between gap-2 px-3 py-2 text-sm font-normal',
                                    index === active && 'bg-brand/10',
                                    !option.value && 'text-ink-soft',
                                )}
                            >
                                <span className="truncate">
                                    {option.label}
                                    {option.hint && (
                                        <span className="ms-2 text-xs text-ink-soft">
                                            {option.hint}
                                        </span>
                                    )}
                                </span>
                                {option.value === value && (
                                    <Check
                                        size={15}
                                        aria-hidden="true"
                                        className="shrink-0 text-brand-text"
                                    />
                                )}
                            </li>
                        ))}
                        {shown.length === 0 && (
                            <li className="px-3 py-3 text-center text-sm font-normal text-ink-soft">
                                {t('No matches')}
                            </li>
                        )}
                    </ul>
                </div>
            )}
        </div>
    );
}
