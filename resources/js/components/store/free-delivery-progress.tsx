import { PartyPopper, Truck } from "lucide-react";
import { useTranslations } from "@/hooks/use-translations";

/**
 * How far the order is from free delivery, as a bar that fills up, and a
 * thank-you once it is there. Only a preview: the server works the fee out.
 */
export default function FreeDeliveryProgress({
    total,
    threshold,
    money,
}: {
    total: number;
    threshold: number;
    /** Formats an amount the way the rest of the page does. */
    money: (amount: number) => string;
}) {
    const t = useTranslations();

    if (!(threshold > 0)) {
        return null;
    }

    const reached = total >= threshold;
    const percent = Math.min(100, Math.round((total / threshold) * 100));

    return (
        <div
            role="status"
            className="rounded-xl border border-emerald-600/20 bg-emerald-600/5 p-3 text-sm"
        >
            <p className="flex items-center gap-2 font-semibold text-emerald-800 dark:text-emerald-300">
                {reached ? (
                    <PartyPopper size={16} aria-hidden="true" />
                ) : (
                    <Truck size={16} aria-hidden="true" />
                )}
                {reached
                    ? t("store.free_delivery_reached")
                    : t("store.free_delivery_progress", {
                          amount: money(threshold - total),
                      })}
            </p>
            <div
                className="mt-2 h-2 overflow-hidden rounded-full bg-emerald-600/15"
                aria-hidden="true"
            >
                <div
                    className="h-full rounded-full bg-emerald-600 transition-[width] duration-500 motion-reduce:transition-none"
                    style={{ width: `${percent}%` }}
                />
            </div>
        </div>
    );
}
