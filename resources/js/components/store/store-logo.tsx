import { usePage } from "@inertiajs/react";
import { useState } from "react";
import { cn } from "@/lib/utils";

/**
 * The shop's logo from its settings, or its name when there is none (or the
 * picture fails to load). The name's first letter takes the brand colour.
 */
export default function StoreLogo({
    className,
    imageClassName = "h-9",
}: {
    className?: string;
    imageClassName?: string;
}) {
    const { store } = usePage().props;
    const [failed, setFailed] = useState(false);

    if (store.logo && !failed) {
        return (
            <img
                src={store.logo}
                alt={store.name}
                className={cn(
                    "w-auto max-w-[180px] object-contain",
                    imageClassName,
                )}
                onError={() => setFailed(true)}
            />
        );
    }

    return (
        <span className={cn("font-black tracking-tight", className)}>
            <span className="text-brand-text">{store.name.charAt(0)}</span>
            {store.name.slice(1)}
        </span>
    );
}
