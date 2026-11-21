import type { AdminShell } from '@/types/admin';
import type { Auth } from '@/types/auth';
import type { Seo } from '@/lib/seo';
import type { CartSummary, FooterData } from '@/types/store';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            /** The shop's name and logo from its settings. */
            store: { name: string; logo: string | null };
            auth: Auth;
            locale: string;
            direction: 'ltr' | 'rtl';
            flash: {
                success?: string | null;
                error?: string | null;
                highlight?: number | null;
            };
            /** Interface text by language: ui[locale]. */
            ui: Record<string, Record<string, unknown>>;
            admin: AdminShell | null;
            cart: CartSummary | null;
            footer: FooterData | null;
            /** Admin text by language: adminText[locale]. */
            adminText: Record<string, Record<string, string>>;
            themeAllowsDark: boolean;
            themePreview: boolean;
            sidebarOpen: boolean;
            seo?: Seo | null;
            [key: string]: unknown;
        };
    }
}
