import type { ReactNode } from "react";
import { Toaster } from "@/components/ui/sonner";
import { TooltipProvider } from "@/components/ui/tooltip";
import AppLayout from "@/layouts/app-layout";
import AuthLayout from "@/layouts/auth-layout";
import SettingsLayout from "@/layouts/settings/layout";

/**
 * Shared by app.tsx (the browser) and ssr.tsx (server-side rendering), so both
 * build the same page around the same layouts.
 */
let storeName: string = import.meta.env.VITE_APP_NAME || "Store";

/** The shop's name from its settings (the shared `store` prop), for titles. */
export function setStoreName(name: string | null | undefined) {
    if (name) {
        storeName = name;
    }
}

export const title = (pageTitle: string) =>
    pageTitle ? `${pageTitle} - ${storeName}` : storeName;

export function layout(name: string) {
    switch (true) {
        case name.startsWith("admin/"):
        case name.startsWith("storefront/"):
            return null;
        case name.startsWith("auth/"):
            return AuthLayout;
        case name.startsWith("settings/"):
            return [AppLayout, SettingsLayout];
        default:
            return AppLayout;
    }
}

export function withApp(app: ReactNode) {
    return (
        <TooltipProvider delayDuration={0}>
            {app}
            {/* On phones, above the store's and the admin's bottom tab bars. */}
            <Toaster
                mobileOffset={{
                    bottom: "calc(5rem + env(safe-area-inset-bottom))",
                }}
            />
        </TooltipProvider>
    );
}
