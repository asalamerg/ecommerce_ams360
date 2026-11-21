import { createInertiaApp } from '@inertiajs/react';
import { layout, title, withApp } from '@/inertia-options';

/**
 * Server-side rendering entry. The @inertiajs/vite plugin wraps this call in
 * its SSR server during `npm run build:ssr`; run it with
 * `php artisan inertia:start-ssr`. Browser-only setup stays in app.tsx.
 * Keep the call a plain statement: the plugin does not detect `export default`.
 */
void createInertiaApp({
    title,
    layout,
    withApp,
});
