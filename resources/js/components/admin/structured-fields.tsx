import { Plus, X } from 'lucide-react';
import SearchableSelect from '@/components/admin/searchable-select';
import type { SelectOption } from '@/components/admin/searchable-select';
import { useAdminText } from '@/hooks/use-admin-text';

/**
 * Form controls for the structured admin module fields. Each one keeps its
 * value as the JSON string the server expects (see App\Support\ModuleFieldValues),
 * so the generic module form can send it like any other field.
 */

function parse<T>(value: string, fallback: T): T {
    try {
        const parsed: unknown = value ? JSON.parse(value) : fallback;

        return (parsed ?? fallback) as T;
    } catch {
        return fallback;
    }
}

export function RelationsField({
    value,
    choices,
    onChange,
}: {
    value: string;
    choices: SelectOption[];
    onChange: (value: string) => void;
}) {
    const t = useAdminText();
    const ids = parse<(number | string)[]>(value, []).map(String);
    const byId = new Map(choices.map((choice) => [choice.value, choice]));

    function save(next: string[]) {
        onChange(next.length ? JSON.stringify(next.map(Number)) : '');
    }

    return (
        <div className="grid gap-2">
            <SearchableSelect
                value=""
                options={choices.filter(
                    (choice) => !ids.includes(choice.value),
                )}
                onChange={(id) => id && save([...ids, id])}
                emptyLabel={t('Add…')}
                placeholder={t('Search')}
            />
            {ids.length > 0 && (
                <ul className="flex flex-wrap gap-2">
                    {ids.map((id) => (
                        <li
                            key={id}
                            className="inline-flex items-center gap-1 rounded-full border border-line bg-surface py-1 ps-3 pe-1 text-xs font-semibold"
                        >
                            {byId.get(id)?.label ?? `#${id}`}
                            <button
                                type="button"
                                aria-label={t('Remove')}
                                onClick={() =>
                                    save(ids.filter((other) => other !== id))
                                }
                                className="grid h-5 w-5 place-items-center rounded-full hover:bg-rose-100 hover:text-rose-700"
                            >
                                <X size={12} aria-hidden="true" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

type Day = { closed: boolean; open?: string; close?: string };

const days = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'] as const;
const dayNames: Record<(typeof days)[number], string> = {
    sun: 'Sunday',
    mon: 'Monday',
    tue: 'Tuesday',
    wed: 'Wednesday',
    thu: 'Thursday',
    fri: 'Friday',
    sat: 'Saturday',
};

export function HoursField({
    value,
    onChange,
}: {
    value: string;
    onChange: (value: string) => void;
}) {
    const t = useAdminText();
    const hours = parse<Partial<Record<string, Day>>>(value, {});

    function update(day: string, next: Day | null) {
        const copy = { ...hours };

        if (next === null) {
            delete copy[day];
        } else {
            copy[day] = next;
        }

        onChange(Object.keys(copy).length ? JSON.stringify(copy) : '');
    }

    return (
        <div className="grid gap-2">
            {days.map((day) => {
                const entry = hours[day];
                const state = entry ? (entry.closed ? 'closed' : 'open') : '';

                return (
                    <div
                        key={day}
                        className="grid grid-cols-[6.5rem_1fr] items-center gap-2 sm:grid-cols-[7rem_9rem_1fr]"
                    >
                        <span className="text-sm font-semibold">
                            {t(dayNames[day])}
                        </span>
                        <select
                            value={state}
                            onChange={(event) =>
                                update(
                                    day,
                                    event.target.value === ''
                                        ? null
                                        : event.target.value === 'closed'
                                          ? { closed: true }
                                          : {
                                                closed: false,
                                                open: entry?.open ?? '09:00',
                                                close: entry?.close ?? '18:00',
                                            },
                                )
                            }
                            className="admin-input"
                            aria-label={t(dayNames[day])}
                        >
                            <option value="">{t('Not set')}</option>
                            <option value="open">{t('Open')}</option>
                            <option value="closed">{t('Closed')}</option>
                        </select>
                        {state === 'open' && entry && (
                            <div
                                className="col-span-2 flex items-center gap-2 sm:col-span-1"
                                dir="ltr"
                            >
                                <input
                                    type="time"
                                    value={entry.open ?? ''}
                                    onChange={(event) =>
                                        update(day, {
                                            ...entry,
                                            open: event.target.value,
                                        })
                                    }
                                    className="admin-input"
                                    aria-label={t('Opens at')}
                                />
                                <span aria-hidden="true">–</span>
                                <input
                                    type="time"
                                    value={entry.close ?? ''}
                                    onChange={(event) =>
                                        update(day, {
                                            ...entry,
                                            close: event.target.value,
                                        })
                                    }
                                    className="admin-input"
                                    aria-label={t('Closes at')}
                                />
                            </div>
                        )}
                    </div>
                );
            })}
        </div>
    );
}

export function ListField({
    value,
    onChange,
}: {
    value: string;
    onChange: (value: string) => void;
}) {
    const t = useAdminText();
    const lines = parse<string[]>(value, []);

    function save(next: string[]) {
        onChange(next.length ? JSON.stringify(next) : '');
    }

    return (
        <div className="grid gap-2">
            {lines.map((line, index) => (
                <div key={index} className="flex gap-2">
                    <input
                        value={line}
                        maxLength={200}
                        onChange={(event) =>
                            save(
                                lines.map((other, at) =>
                                    at === index ? event.target.value : other,
                                ),
                            )
                        }
                        className="admin-input min-w-0 flex-1"
                    />
                    <button
                        type="button"
                        aria-label={t('Remove')}
                        onClick={() =>
                            save(lines.filter((_, at) => at !== index))
                        }
                        className="grid w-10 shrink-0 place-items-center rounded-lg border border-line hover:border-rose-400 hover:text-rose-700"
                    >
                        <X size={16} aria-hidden="true" />
                    </button>
                </div>
            ))}
            {lines.length < 30 && (
                <button
                    type="button"
                    onClick={() => save([...lines, ''])}
                    className="inline-flex w-fit items-center gap-1.5 text-sm font-bold text-brand-text hover:underline"
                >
                    <Plus size={15} aria-hidden="true" />
                    {t('Add line')}
                </button>
            )}
        </div>
    );
}

/** Read-only photos, such as those a customer sent with a repair request. */
export function ImagesField({ value }: { value: string }) {
    const t = useAdminText();
    const urls = parse<unknown[]>(value, []).filter(
        (url): url is string =>
            typeof url === 'string' &&
            (url.startsWith('https://') || url.startsWith('/storage/')),
    );

    if (!urls.length) {
        return <p className="text-sm text-ink-soft">{t('No photos.')}</p>;
    }

    return (
        <div className="flex flex-wrap gap-2">
            {urls.map((url) => (
                <a
                    key={url}
                    href={url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="block h-24 w-24 overflow-hidden rounded-lg border border-line"
                >
                    <img
                        src={url}
                        alt=""
                        loading="lazy"
                        className="h-full w-full object-cover"
                    />
                </a>
            ))}
        </div>
    );
}
