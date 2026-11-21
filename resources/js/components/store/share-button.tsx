import { Check, Share2 } from "lucide-react";
import { useState } from "react";
import { toast } from "sonner";
import { useTranslations } from "@/hooks/use-translations";

/**
 * Shares the page through the phone's own share sheet (WhatsApp, Instagram,
 * messages...). Where the browser has none, the link is copied instead.
 */
export default function ShareButton({ title }: { title: string }) {
    const t = useTranslations();
    const [copied, setCopied] = useState(false);

    async function share() {
        const url = window.location.href;

        if (typeof navigator.share === "function") {
            try {
                await navigator.share({ title, url });
            } catch {
                // Closing the share sheet is not an error worth showing.
            }

            return;
        }

        try {
            await navigator.clipboard.writeText(url);
            setCopied(true);
            toast.success(t("store.link_copied"));
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            toast.error(t("store.link_copy_failed"));
        }
    }

    return (
        <button
            type="button"
            onClick={share}
            className="inline-flex items-center gap-2 rounded-btn border border-line px-4 py-2 text-sm font-bold transition hover:border-brand hover:text-brand-text"
        >
            {copied ? (
                <Check size={16} aria-hidden="true" />
            ) : (
                <Share2 size={16} aria-hidden="true" />
            )}
            {t("store.share")}
        </button>
    );
}
