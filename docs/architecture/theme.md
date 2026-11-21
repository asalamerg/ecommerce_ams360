# Store theme

How the appearance of the store and admin panel is stored, edited, and delivered.

## How a color reaches the page

`ThemeCatalog` holds the tokens, presets, fonts, and corner styles. `ThemeCatalog::normalize()` accepts only strict `#RRGGBB` colors and known ids, so a bad row cannot inject CSS. `ThemeStylesheet` turns a normalized theme into CSS variables, and `ShareStoreTheme` prints them in `app.blade.php`.

The inline `<style>` must stay after `@vite`, otherwise the defaults in `app.css` cancel it. A test covers this.

Components use semantic Tailwind tokens such as `bg-brand`, `text-ink`, `bg-panel` and `border-line`, defined with `@theme inline` in `resources/css/app.css`. Do not add fixed colors such as `bg-orange-500` to the store or admin: they ignore the theme and dark mode. Status colors for success and errors are the exception.

`ThemeStylesheet` is the only place colors are derived. That covers readable text on the top bar and footer, a WCAG AA brand color for text (`--brand-accent`), and the dark palette. The editor's live preview receives its CSS from the server, so it cannot drift from the real result.

## Data

| Table | Holds |
| --- | --- |
| `site_themes`, row 1 | `theme` (published), `draft`, `version` |
| `theme_snapshots` | `kind`: `history` (last 10), `saved` (last 30), `schedule` |

`ThemeManager` owns every write. Each one runs in a transaction on a locked row and checks `version`, so a stale tab gets an error instead of undoing a newer change. Publishing is atomic and records who published.

## Lifecycle

- **Draft:** each edit autosaves after 350 ms, one request at a time. Visitors never receive it.
- **Preview on site:** sets a session flag, and only an administrator's session honours it.
- **Publish:** copies the draft to `theme` and stores the previous theme in history. It uses a full page load because client-side visits keep the old inline styles.
- **Schedules:** a running schedule wins over the published theme. Overlaps are refused. `liveTheme()` is cached for 60 seconds, so a schedule can start up to a minute late. Ended schedules are pruned after 30 days by `model:prune`.

## Dark mode

Dark mode exists only when the live theme enables it. `app.blade.php` sets `data-dark-allowed`, and `use-appearance.tsx` ignores the visitor's preference otherwise.

## Fonts

Fonts are self-hosted through the `bunny()` entries in `vite.config.ts`, so the store makes no request to a font CDN. Browsers download only the faces a page uses. A new font needs an entry there and in `ThemeCatalog::FONTS`. Arabic fonts have no Hebrew glyphs, so the stack falls back to Heebo.

## Adding a preset

Add it to `ThemeCatalog::presets()` and give it a name in `presetNames` in `resources/js/pages/admin/theme/index.tsx` and in `lang/ar.json` and `lang/he.json`. A test checks that every preset is complete and passes the contrast rules.
