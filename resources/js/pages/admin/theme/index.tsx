import { router, useForm, usePage } from "@inertiajs/react";
import {
    AlertTriangle,
    CalendarClock,
    Check,
    Eye,
    History,
    Palette,
    RotateCcw,
    Save,
    Trash2,
    Upload,
} from "lucide-react";
import type { FormEvent, ReactNode } from "react";
import { useEffect, useRef, useState } from "react";
import {
    FieldError,
    PageHeader,
    panel,
    primaryButton,
    secondaryButton,
} from "@/components/admin/admin-ui";
import { useAdminText, useDateFormatter } from "@/hooks/use-admin-text";
import StoreAdminLayout from "@/layouts/store-admin-layout";
import { cn } from "@/lib/utils";
import StoreLogo from "@/components/store/store-logo";

type Colors = Record<string, string>;
type Theme = {
    preset: string;
    colors: Colors;
    font: string;
    radius: string;
    darkMode: boolean;
    logoFavicon: boolean;
};
type Snapshot = {
    id: number;
    name: string | null;
    colors: Colors;
    actor: string | null;
    created_at: string | null;
    starts_at: string | null;
    ends_at: string | null;
};
type Props = {
    editor: {
        draft: Theme;
        version: number;
        hasChanges: boolean;
        previewCss: string;
        warnings: string[];
    };
    activeSchedule: { name: string; ends_at: string } | null;
    history: Snapshot[];
    saved: Snapshot[];
    schedules: Snapshot[];
    catalog: {
        presets: Record<string, Colors>;
        groups: Record<string, string[]>;
        fonts: Record<string, string | null>;
        radii: string[];
    };
    hasLogo: boolean;
};

const editorProps = [
    "editor",
    "saved",
    "history",
    "schedules",
    "flash",
    "errors",
];

const tokenText: Record<string, { name: string; use: string }> = {
    primary: {
        name: "Primary color (brand)",
        use: "Main buttons, the active menu item, and the input focus border.",
    },
    primaryHover: {
        name: "Primary hover color",
        use: "Shown when hovering main buttons. Best as a slightly darker shade of the primary color.",
    },
    onPrimary: {
        name: "Text on primary color",
        use: "Text inside main buttons. Keep white for dark primaries; pick black when the primary is light (e.g. gold or lime).",
    },
    secondary: {
        name: "Secondary color (dark)",
        use: "Headings and the main text color on every page.",
    },
    muted: {
        name: "Secondary text color",
        use: "Lighter text across the site: descriptions, dates, and form hints.",
    },
    topBar: {
        name: "Top bar",
        use: "The thin strip at the very top of the store, the home page banner, and the admin side menu. Its text color is picked automatically to stay readable.",
    },
    headerBg: {
        name: "Header background",
        use: "Background of the main bar with the logo and menu.",
    },
    footer: {
        name: "Footer background",
        use: "Background of the bottom section of every page. Its text color is picked automatically to stay readable.",
    },
    background: {
        name: "Page background",
        use: "Background of form fields. Light borders and dividers are derived from it too.",
    },
    surface: {
        name: "Light surface color",
        use: "Background of the pages behind cards, and table headers in the admin panel.",
    },
    card: {
        name: "Card background",
        use: "Background of product and category cards, and admin panel cards.",
    },
    sale: {
        name: "Discount badge",
        use: "The discount badge on product cards. Its text is white.",
    },
    urgent: {
        name: "Low stock alert",
        use: "The alert shown when a product is running low.",
    },
};

const groupNames: Record<string, string> = {
    brand: "Brand & buttons",
    layout: "Header, footer & text",
    surfaces: "Backgrounds & cards",
    status: "Discounts & alerts",
};

const presetNames: Record<string, string> = {
    default: "Store default",
    ocean: "Ocean blue",
    emerald: "Emerald green",
    royal: "Royal purple",
    crimson: "Crimson red",
    gold: "Gold & black",
    midnight: "Midnight",
    sunset: "Sunset",
    teal: "Teal",
    rose: "Rose",
    indigo: "Tech indigo",
    lime: "Lime & charcoal",
    coffee: "Coffee",
    mono: "Black & white",
    sky: "Clear sky",
    plum: "Plum",
    forest: "Forest",
    graphite: "Graphite & amber",
};

const radiusNames: Record<string, string> = {
    rounded: "Rounded",
    medium: "Medium",
    sharp: "Sharp",
};

function Swatches({ colors }: { colors: Colors }) {
    return (
        <span
            aria-hidden="true"
            className="flex h-5 overflow-hidden rounded-md"
        >
            {["primary", "secondary", "topBar", "surface", "sale"].map(
                (token) => (
                    <span
                        key={token}
                        className="flex-1"
                        style={{ backgroundColor: colors[token] }}
                    />
                ),
            )}
        </span>
    );
}

function Section({
    icon,
    title,
    hint,
    children,
}: {
    icon?: ReactNode;
    title: string;
    hint?: string;
    children: ReactNode;
}) {
    return (
        <section className={`${panel} p-5 sm:p-6`}>
            <h2 className="flex items-center gap-2 text-lg font-extrabold">
                {icon}
                {title}
            </h2>
            {hint && <p className="mt-1 text-sm text-ink-soft">{hint}</p>}
            <div className="mt-4">{children}</div>
        </section>
    );
}

function LivePreview({ css, allowDark }: { css: string; allowDark: boolean }) {
    const t = useAdminText();
    const [dark, setDark] = useState(false);

    return (
        <section className={`${panel} p-4`}>
            <div className="mb-3 flex items-center justify-between gap-3">
                <h2 className="font-extrabold">{t("Live preview")}</h2>
                {allowDark && (
                    <label className="flex items-center gap-2 text-xs font-semibold">
                        <input
                            type="checkbox"
                            checked={dark}
                            onChange={(event) => setDark(event.target.checked)}
                            className="admin-checkbox"
                        />
                        {t("Preview dark mode")}
                    </label>
                )}
            </div>
            <style>{css}</style>
            <div
                data-test="theme-preview"
                className={cn(
                    "theme-preview-box overflow-hidden rounded-xl border border-line bg-surface text-ink",
                    dark && "preview-dark",
                )}
            >
                <div className="bg-topbar px-3 py-1.5 text-xs text-on-topbar">
                    {t("Top bar • Order by phone")}
                </div>
                <div className="flex items-center justify-between bg-header px-3 py-2.5">
                    <span className="font-black text-ink">
                        <StoreLogo imageClassName="h-6" />
                    </span>
                    <span className="text-xs text-ink-soft">
                        {t("Home • Cart")}
                    </span>
                </div>
                <div className="p-3">
                    <div className="flex items-center justify-between">
                        <p className="text-sm font-black text-ink">
                            {t("Section title")}
                        </p>
                        <span className="text-xs font-bold text-brand-text">
                            {t("View all")}
                        </span>
                    </div>
                    <div className="relative mt-2 rounded-panel border border-line bg-panel p-3">
                        <span className="absolute end-2 top-2 rounded-full bg-sale px-2 py-0.5 text-[10px] font-bold text-white">
                            -20%
                        </span>
                        <div className="h-16 rounded-lg bg-surface" />
                        <p className="mt-2 text-sm font-bold text-ink">
                            {t("Product name")}
                        </p>
                        <p className="text-sm font-black text-brand-text">
                            ₪1,299
                        </p>
                        <p className="text-xs text-ink-soft">
                            {t("In stock • Delivery in 2 days")}
                        </p>
                        <p className="text-xs font-bold text-urgent">
                            {t("Only 3 left!")}
                        </p>
                        <input
                            readOnly
                            tabIndex={-1}
                            value={t("Input field")}
                            className="admin-input mt-2"
                        />
                        <div className="mt-2 flex flex-wrap gap-2">
                            <span className="rounded-btn bg-brand px-3 py-1.5 text-xs font-bold text-on-brand">
                                {t("Add to cart")}
                            </span>
                            <span className="rounded-btn bg-brand-hover px-3 py-1.5 text-xs font-bold text-on-brand">
                                {t("On hover")}
                            </span>
                            <span className="rounded-btn border border-ink px-3 py-1.5 text-xs font-bold text-ink">
                                {t("Secondary")}
                            </span>
                        </div>
                    </div>
                </div>
                <div className="bg-footer px-3 py-2 text-xs text-on-footer">
                    {t("Footer — bottom of page")}
                </div>
            </div>
        </section>
    );
}

export default function ThemeEditor({
    editor,
    activeSchedule,
    history,
    saved,
    schedules,
    catalog,
    hasLogo,
}: Props) {
    const { errors } = usePage().props;
    const t = useAdminText();
    const formatDate = useDateFormatter();

    const [theme, setTheme] = useState<Theme>(editor.draft);
    const [saving, setSaving] = useState(false);
    const [justSaved, setJustSaved] = useState(false);

    // Saves run one at a time, each with the version the previous one returned.
    const latest = useRef({ theme, version: editor.version, inFlight: false });
    const pending = useRef(false);
    const afterSave = useRef<(() => void) | null>(null);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        latest.current.version = editor.version;
    }, [editor.version]);

    function send() {
        if (latest.current.inFlight) {
            pending.current = true;

            return;
        }

        latest.current.inFlight = true;
        setSaving(true);

        router.put(
            "/admin/theme/draft",
            { theme: latest.current.theme, version: latest.current.version },
            {
                preserveState: true,
                preserveScroll: true,
                only: editorProps,
                onSuccess: () => setJustSaved(true),
                onFinish: () => {
                    latest.current.inFlight = false;
                    setSaving(false);

                    if (pending.current) {
                        pending.current = false;
                        send();

                        return;
                    }

                    afterSave.current?.();
                    afterSave.current = null;
                },
            },
        );
    }

    function update(next: Theme) {
        setTheme(next);
        setJustSaved(false);
        latest.current.theme = next;

        if (timer.current) {
            clearTimeout(timer.current);
        }

        timer.current = setTimeout(() => {
            timer.current = null;
            send();
        }, 350);
    }

    /** Runs an action once every edit has reached the server. */
    function afterFlush(action: () => void) {
        if (timer.current) {
            clearTimeout(timer.current);
            timer.current = null;
            afterSave.current = action;
            send();
        } else if (latest.current.inFlight) {
            afterSave.current = action;
        } else {
            action();
        }
    }

    /** Posts an action; `adopt` loads the draft it produced into the editor. */
    function versioned(url: string, adopt = false) {
        afterFlush(() =>
            router.post(
                url,
                { version: latest.current.version },
                {
                    preserveScroll: true,
                    onSuccess: (page) => {
                        if (adopt) {
                            const { draft } = (page.props as unknown as Props)
                                .editor;

                            setTheme(draft);
                            latest.current.theme = draft;
                        }
                    },
                },
            ),
        );
    }

    function setColor(token: string, value: string) {
        update({
            ...theme,
            preset: "custom",
            colors: { ...theme.colors, [token]: value },
        });
    }

    const nameForm = useForm({ name: "" });
    const scheduleForm = useForm({
        name: "",
        source: "draft",
        starts_at: "",
        ends_at: "",
    });

    function saveNamed(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        afterFlush(() =>
            nameForm.post("/admin/theme/saved", {
                preserveScroll: true,
                onSuccess: () => nameForm.reset(),
            }),
        );
    }

    function addSchedule(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        // datetime-local has no zone, so it is converted while the browser's is known.
        scheduleForm.transform((data) => ({
            ...data,
            starts_at: data.starts_at
                ? new Date(data.starts_at).toISOString()
                : "",
            ends_at: data.ends_at ? new Date(data.ends_at).toISOString() : "",
        }));
        afterFlush(() =>
            scheduleForm.post("/admin/theme/schedules", {
                preserveScroll: true,
                onSuccess: () => scheduleForm.reset(),
            }),
        );
    }

    function remove(id: number) {
        if (window.confirm(t("Are you sure you want to delete this?"))) {
            router.delete(`/admin/theme/snapshots/${id}`, {
                preserveScroll: true,
            });
        }
    }

    function scheduleState(schedule: Snapshot): string {
        const now = Date.now();

        if (new Date(schedule.ends_at ?? 0).getTime() <= now) {
            return "Ended";
        }

        return new Date(schedule.starts_at ?? 0).getTime() <= now
            ? "Running now"
            : "Upcoming";
    }

    const conflict = (errors as Record<string, string>).theme;

    return (
        <StoreAdminLayout title={t("Theme")}>
            <PageHeader
                title={t("Colors & Theme")}
                description={t(
                    'Change colors, font and corner style freely: every change is auto-saved as a draft customers don\'t see. Preview it on the site, then press "Publish" to make it live.',
                )}
            />

            <div className="sticky top-16 z-10 -mx-1 mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-line bg-panel px-3 py-2.5 shadow-sm sm:px-4 sm:py-3">
                <p
                    aria-live="polite"
                    className="text-xs font-semibold sm:text-sm"
                >
                    {saving
                        ? t("Saving...")
                        : justSaved
                          ? `✓ ${t("Draft saved automatically")}`
                          : editor.hasChanges
                            ? t(
                                  "You have unpublished changes (draft) — customers still see the previous theme",
                              )
                            : t(
                                  "Everything is published — this is what customers see now",
                              )}
                </p>
                <div className="flex flex-wrap gap-2">
                    {/* Below xl the preview sits after every setting. */}
                    <a
                        href="#theme-live-preview"
                        className={`${secondaryButton} xl:hidden`}
                    >
                        <Eye size={16} aria-hidden="true" />
                        {t("Live preview")}
                    </a>
                    <a
                        href="/admin/theme/preview"
                        target="_blank"
                        rel="noreferrer"
                        className={secondaryButton}
                    >
                        <Eye size={16} aria-hidden="true" />
                        {t("Preview on site")}
                    </a>
                    {editor.hasChanges && (
                        <button
                            type="button"
                            className={secondaryButton}
                            onClick={() =>
                                versioned("/admin/theme/discard", true)
                            }
                        >
                            <RotateCcw size={16} aria-hidden="true" />
                            {t("Discard draft")}
                        </button>
                    )}
                    <button
                        type="button"
                        disabled={!editor.hasChanges && !timer.current}
                        className={primaryButton}
                        onClick={() => versioned("/admin/theme/publish")}
                    >
                        <Upload size={16} aria-hidden="true" />
                        {t("Publish")}
                    </button>
                </div>
            </div>

            {conflict && (
                <p
                    role="alert"
                    className="mb-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800"
                >
                    {conflict}
                </p>
            )}

            {activeSchedule && (
                <p className="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900">
                    {t(
                        'Scheduled theme ":name" is running until :until. The published theme returns automatically when it ends.',
                        {
                            name: activeSchedule.name,
                            until: formatDate(activeSchedule.ends_at),
                        },
                    )}
                </p>
            )}

            <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
                <div className="min-w-0 space-y-5">
                    <Section
                        icon={<Palette size={18} aria-hidden="true" />}
                        title={t("Ready-made themes")}
                        hint={t(
                            "Click a theme to apply its colors, then fine-tune any color below.",
                        )}
                    >
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                            {Object.entries(catalog.presets).map(
                                ([id, colors]) => (
                                    <button
                                        key={id}
                                        type="button"
                                        aria-pressed={theme.preset === id}
                                        data-test={`preset-${id}`}
                                        onClick={() =>
                                            update({
                                                ...theme,
                                                preset: id,
                                                colors,
                                            })
                                        }
                                        className={cn(
                                            "rounded-xl border p-2.5 text-start text-sm font-bold transition hover:-translate-y-0.5 hover:shadow-md motion-reduce:transform-none",
                                            theme.preset === id
                                                ? "border-brand ring-2 ring-brand/30"
                                                : "border-line",
                                        )}
                                    >
                                        <Swatches colors={colors} />
                                        <span className="mt-2 flex items-center justify-between gap-1">
                                            {t(presetNames[id] ?? id)}
                                            {theme.preset === id && (
                                                <Check
                                                    size={15}
                                                    aria-hidden="true"
                                                    className="text-brand-text"
                                                />
                                            )}
                                        </span>
                                    </button>
                                ),
                            )}
                        </div>
                        {theme.preset === "custom" && (
                            <p className="mt-3 text-sm text-ink-soft">
                                {t(
                                    "Custom theme — at least one color differs from the preset.",
                                )}
                            </p>
                        )}
                    </Section>

                    <Section
                        title={t("Store colors")}
                        hint={t(
                            "Each color shows exactly where it appears on the site.",
                        )}
                    >
                        <div className="space-y-6">
                            {Object.entries(catalog.groups).map(
                                ([group, tokens]) => (
                                    <div key={group}>
                                        <h3 className="mb-3 text-sm font-bold text-brand-text">
                                            {t(groupNames[group] ?? group)}
                                        </h3>
                                        <div className="space-y-3">
                                            {tokens.map((token) => (
                                                <div
                                                    key={token}
                                                    className="grid items-start gap-3 sm:grid-cols-[9rem_minmax(0,1fr)]"
                                                >
                                                    <div className="flex items-center gap-2">
                                                        <input
                                                            type="color"
                                                            value={
                                                                theme.colors[
                                                                    token
                                                                ]
                                                            }
                                                            aria-label={t(
                                                                tokenText[token]
                                                                    .name,
                                                            )}
                                                            onChange={(event) =>
                                                                setColor(
                                                                    token,
                                                                    event.target.value.toUpperCase(),
                                                                )
                                                            }
                                                            className="h-10 w-10 shrink-0 cursor-pointer rounded-lg border border-line bg-transparent p-0.5"
                                                        />
                                                        <input
                                                            value={
                                                                theme.colors[
                                                                    token
                                                                ]
                                                            }
                                                            maxLength={7}
                                                            dir="ltr"
                                                            aria-label={`${t(tokenText[token].name)} (HEX)`}
                                                            onChange={(
                                                                event,
                                                            ) => {
                                                                const value =
                                                                    event.target
                                                                        .value;

                                                                if (
                                                                    /^#[0-9a-fA-F]{6}$/.test(
                                                                        value,
                                                                    )
                                                                ) {
                                                                    setColor(
                                                                        token,
                                                                        value.toUpperCase(),
                                                                    );
                                                                }
                                                            }}
                                                            className="admin-input font-mono uppercase"
                                                        />
                                                    </div>
                                                    <div>
                                                        <p className="text-sm font-bold">
                                                            {t(
                                                                tokenText[token]
                                                                    .name,
                                                            )}
                                                        </p>
                                                        <p className="text-xs leading-5 text-ink-soft">
                                                            {t(
                                                                tokenText[token]
                                                                    .use,
                                                            )}
                                                        </p>
                                                        {editor.warnings.includes(
                                                            token,
                                                        ) && (
                                                            <p className="mt-1 flex items-start gap-1.5 text-xs font-semibold text-amber-800">
                                                                <AlertTriangle
                                                                    size={14}
                                                                    aria-hidden="true"
                                                                    className="mt-0.5 shrink-0"
                                                                />
                                                                {t(
                                                                    "Warning: text on this color (or this color on its background) will be hard to read. Pick a darker or lighter shade.",
                                                                )}
                                                            </p>
                                                        )}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                ),
                            )}
                        </div>
                    </Section>

                    <Section
                        title={t("Font")}
                        hint={t(
                            "Applies to the whole site. Arabic fonts show Hebrew in the system font; pick Heebo if most of your visitors read Hebrew.",
                        )}
                    >
                        <div className="grid gap-2 sm:grid-cols-2">
                            {Object.entries(catalog.fonts).map(
                                ([id, family]) => (
                                    <button
                                        key={id}
                                        type="button"
                                        aria-pressed={theme.font === id}
                                        onClick={() =>
                                            update({ ...theme, font: id })
                                        }
                                        style={
                                            family
                                                ? {
                                                      fontFamily: `'${family}', sans-serif`,
                                                  }
                                                : undefined
                                        }
                                        className={cn(
                                            "rounded-xl border px-3 py-2.5 text-start transition",
                                            theme.font === id
                                                ? "border-brand ring-2 ring-brand/30"
                                                : "border-line hover:border-brand",
                                        )}
                                    >
                                        <span className="block text-sm font-bold">
                                            {family ??
                                                t("System font (default)")}
                                        </span>
                                        <span className="block text-sm text-ink-soft">
                                            {t("My store — today's deals 123")}
                                        </span>
                                    </button>
                                ),
                            )}
                        </div>
                    </Section>

                    <Section
                        title={t("Corner style")}
                        hint={t(
                            "Controls how rounded buttons, cards and inputs are across the site.",
                        )}
                    >
                        <div className="grid grid-cols-3 gap-2">
                            {catalog.radii.map((radius) => (
                                <button
                                    key={radius}
                                    type="button"
                                    aria-pressed={theme.radius === radius}
                                    onClick={() => update({ ...theme, radius })}
                                    className={cn(
                                        "rounded-xl border px-3 py-3 text-sm font-bold transition",
                                        theme.radius === radius
                                            ? "border-brand ring-2 ring-brand/30"
                                            : "border-line hover:border-brand",
                                    )}
                                >
                                    {t(radiusNames[radius] ?? radius)}
                                </button>
                            ))}
                        </div>
                    </Section>

                    <Section title={t("Options")}>
                        <div className="space-y-4">
                            <label className="flex items-start gap-3">
                                <input
                                    type="checkbox"
                                    checked={theme.darkMode}
                                    onChange={(event) =>
                                        update({
                                            ...theme,
                                            darkMode: event.target.checked,
                                        })
                                    }
                                    className="admin-checkbox mt-0.5"
                                />
                                <span>
                                    <span className="block text-sm font-bold">
                                        {t("Let visitors use dark mode")}
                                    </span>
                                    <span className="block text-xs text-ink-soft">
                                        {t(
                                            "Shows a moon button in the top bar that switches the visitor to a dark version of this theme; the browser remembers their choice.",
                                        )}
                                    </span>
                                </span>
                            </label>
                            <label className="flex items-start gap-3">
                                <input
                                    type="checkbox"
                                    checked={theme.logoFavicon}
                                    onChange={(event) =>
                                        update({
                                            ...theme,
                                            logoFavicon: event.target.checked,
                                        })
                                    }
                                    className="admin-checkbox mt-0.5"
                                />
                                <span>
                                    <span className="block text-sm font-bold">
                                        {t("Use your logo as the tab icon")}
                                    </span>
                                    <span className="block text-xs text-ink-soft">
                                        {t(
                                            hasLogo
                                                ? "The logo uploaded in Settings appears next to the site name in the browser tab instead of the bolt icon."
                                                : "No logo uploaded yet (in Settings), so the bolt icon in theme colors stays until you upload one.",
                                        )}
                                    </span>
                                </span>
                            </label>
                            <button
                                type="button"
                                className={secondaryButton}
                                onClick={() =>
                                    update({
                                        preset: "default",
                                        colors: catalog.presets.default,
                                        font: "cairo",
                                        radius: "rounded",
                                        darkMode: false,
                                        logoFavicon: false,
                                    })
                                }
                            >
                                <RotateCcw size={16} aria-hidden="true" />
                                {t("Reset draft to default settings")}
                            </button>
                        </div>
                    </Section>

                    <Section
                        icon={<Save size={18} aria-hidden="true" />}
                        title={t("My saved themes")}
                        hint={t(
                            'Save the current draft under a name (e.g. "Ramadan theme") to come back to it or schedule it later.',
                        )}
                    >
                        <form
                            onSubmit={saveNamed}
                            className="flex flex-wrap gap-2"
                        >
                            <input
                                value={nameForm.data.name}
                                onChange={(event) =>
                                    nameForm.setData("name", event.target.value)
                                }
                                maxLength={60}
                                required
                                placeholder={t(
                                    "Theme name, e.g. Ramadan theme",
                                )}
                                aria-label={t("Theme name, e.g. Ramadan theme")}
                                className="admin-input min-w-48 flex-1"
                            />
                            <button
                                type="submit"
                                disabled={nameForm.processing}
                                className={secondaryButton}
                            >
                                {t("Save current theme")}
                            </button>
                            <span className="w-full">
                                <FieldError message={nameForm.errors.name} />
                            </span>
                        </form>
                        {saved.length ? (
                            <ul className="mt-4 divide-y divide-line">
                                {saved.map((item) => (
                                    <li
                                        key={item.id}
                                        className="flex flex-wrap items-center gap-3 py-2.5"
                                    >
                                        <span className="w-20">
                                            <Swatches colors={item.colors} />
                                        </span>
                                        <span className="min-w-0 flex-1 truncate text-sm font-bold">
                                            {item.name}
                                        </span>
                                        <button
                                            type="button"
                                            className="text-sm font-bold text-brand-text hover:underline"
                                            onClick={() =>
                                                versioned(
                                                    `/admin/theme/snapshots/${item.id}/apply`,
                                                    true,
                                                )
                                            }
                                        >
                                            {t("Apply to draft")}
                                        </button>
                                        <button
                                            type="button"
                                            aria-label={t("Delete")}
                                            className="text-rose-700"
                                            onClick={() => remove(item.id)}
                                        >
                                            <Trash2
                                                size={16}
                                                aria-hidden="true"
                                            />
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="mt-4 text-sm text-ink-soft">
                                {t("No saved themes yet.")}
                            </p>
                        )}
                    </Section>

                    <Section
                        icon={<CalendarClock size={18} aria-hidden="true" />}
                        title={t("Scheduled seasonal themes")}
                        hint={t(
                            "Pick a theme and a time window (e.g. Black Friday or Ramadan): it switches on automatically at the start and the published theme returns automatically at the end.",
                        )}
                    >
                        <form
                            onSubmit={addSchedule}
                            className="grid gap-3 sm:grid-cols-2"
                        >
                            <label className="grid gap-1.5 text-sm font-semibold">
                                {t("Occasion name")}
                                <input
                                    value={scheduleForm.data.name}
                                    onChange={(event) =>
                                        scheduleForm.setData(
                                            "name",
                                            event.target.value,
                                        )
                                    }
                                    maxLength={60}
                                    required
                                    placeholder={t("e.g. Black Friday")}
                                    className="admin-input"
                                />
                                <FieldError
                                    message={scheduleForm.errors.name}
                                />
                            </label>
                            <label className="grid gap-1.5 text-sm font-semibold">
                                {t("Theme")}
                                <select
                                    value={scheduleForm.data.source}
                                    onChange={(event) =>
                                        scheduleForm.setData(
                                            "source",
                                            event.target.value,
                                        )
                                    }
                                    className="admin-input"
                                >
                                    <option value="draft">
                                        {t("Current draft")}
                                    </option>
                                    {saved.map((item) => (
                                        <option key={item.id} value={item.id}>
                                            {item.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="grid gap-1.5 text-sm font-semibold">
                                {t("Starts at")}
                                <input
                                    type="datetime-local"
                                    required
                                    value={scheduleForm.data.starts_at}
                                    onChange={(event) =>
                                        scheduleForm.setData(
                                            "starts_at",
                                            event.target.value,
                                        )
                                    }
                                    className="admin-input"
                                />
                                <FieldError
                                    message={scheduleForm.errors.starts_at}
                                />
                            </label>
                            <label className="grid gap-1.5 text-sm font-semibold">
                                {t("Ends at")}
                                <input
                                    type="datetime-local"
                                    required
                                    value={scheduleForm.data.ends_at}
                                    onChange={(event) =>
                                        scheduleForm.setData(
                                            "ends_at",
                                            event.target.value,
                                        )
                                    }
                                    className="admin-input"
                                />
                                <FieldError
                                    message={scheduleForm.errors.ends_at}
                                />
                            </label>
                            <div className="sm:col-span-2">
                                <button
                                    type="submit"
                                    disabled={scheduleForm.processing}
                                    className={secondaryButton}
                                >
                                    {t("Add schedule")}
                                </button>
                            </div>
                        </form>
                        {schedules.length ? (
                            <ul className="mt-4 divide-y divide-line">
                                {schedules.map((item) => (
                                    <li
                                        key={item.id}
                                        className="flex flex-wrap items-center gap-3 py-2.5"
                                    >
                                        <span className="w-20">
                                            <Swatches colors={item.colors} />
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-sm font-bold">
                                                {item.name}
                                            </span>
                                            <span className="block text-xs text-ink-soft">
                                                {item.starts_at &&
                                                    formatDate(item.starts_at)}
                                                {" — "}
                                                {item.ends_at &&
                                                    formatDate(item.ends_at)}
                                            </span>
                                        </span>
                                        <span className="rounded-full bg-surface px-2.5 py-1 text-xs font-bold">
                                            {t(scheduleState(item))}
                                        </span>
                                        <button
                                            type="button"
                                            aria-label={t("Delete")}
                                            className="text-rose-700"
                                            onClick={() => remove(item.id)}
                                        >
                                            <Trash2
                                                size={16}
                                                aria-hidden="true"
                                            />
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="mt-4 text-sm text-ink-soft">
                                {t("No scheduled themes.")}
                            </p>
                        )}
                    </Section>

                    <Section
                        icon={<History size={18} aria-hidden="true" />}
                        title={t("Publish history (undo)")}
                        hint={t(
                            'The last 10 themes that were live before each publish. Press "Restore" to load one into the draft, then publish it.',
                        )}
                    >
                        {history.length ? (
                            <ul className="divide-y divide-line">
                                {history.map((item) => (
                                    <li
                                        key={item.id}
                                        className="flex flex-wrap items-center gap-3 py-2.5"
                                    >
                                        <span className="w-20">
                                            <Swatches colors={item.colors} />
                                        </span>
                                        <span className="min-w-0 flex-1 text-xs text-ink-soft">
                                            {item.created_at &&
                                                formatDate(item.created_at)}
                                            {item.actor && ` · ${item.actor}`}
                                        </span>
                                        <button
                                            type="button"
                                            className="text-sm font-bold text-brand-text hover:underline"
                                            onClick={() =>
                                                versioned(
                                                    `/admin/theme/snapshots/${item.id}/apply`,
                                                    true,
                                                )
                                            }
                                        >
                                            {t("Restore")}
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-sm text-ink-soft">
                                {t("Nothing published yet, history is empty.")}
                            </p>
                        )}
                    </Section>
                </div>

                <div
                    id="theme-live-preview"
                    className="scroll-mt-40 xl:sticky xl:top-36 xl:self-start"
                >
                    <LivePreview
                        css={editor.previewCss}
                        allowDark={theme.darkMode}
                    />
                </div>
            </div>
        </StoreAdminLayout>
    );
}
