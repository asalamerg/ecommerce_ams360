import { usePage } from '@inertiajs/react';
import { Moon, Sun } from 'lucide-react';
import { useAppearance } from '@/hooks/use-appearance';
import { useTranslations } from '@/hooks/use-translations';

/**
 * Shown only when the live store theme allows dark mode.
 */
export default function ColorModeToggle({ className }: { className?: string }) {
    const { themeAllowsDark } = usePage().props;
    const { resolvedAppearance, updateAppearance } = useAppearance();
    const t = useTranslations();

    if (!themeAllowsDark) {
        return null;
    }

    const isDark = resolvedAppearance === 'dark';
    const label = t(isDark ? 'nav.light_mode' : 'nav.dark_mode');

    return (
        <button
            type="button"
            aria-label={label}
            title={label}
            onClick={() => updateAppearance(isDark ? 'light' : 'dark')}
            className={className}
        >
            {isDark ? (
                <Sun size={16} aria-hidden="true" />
            ) : (
                <Moon size={16} aria-hidden="true" />
            )}
        </button>
    );
}
