import { useSyncExternalStore } from 'react';

const KEY = 'store.favorites';
const MAX = 60;
const listeners = new Set<() => void>();
let cache: number[] | null = null;

function read(): number[] {
    if (cache !== null) {
        return cache;
    }

    try {
        const parsed: unknown = JSON.parse(localStorage.getItem(KEY) ?? '[]');
        cache = Array.isArray(parsed)
            ? parsed.filter(
                  (id): id is number => Number.isInteger(id) && id > 0,
              )
            : [];
    } catch {
        cache = [];
    }

    return cache;
}

function write(ids: number[]): void {
    cache = ids.slice(0, MAX);

    try {
        localStorage.setItem(KEY, JSON.stringify(cache));
    } catch {
        // Private mode or a full disk: the list still works until the tab closes.
    }

    listeners.forEach((listener) => listener());
}

function subscribe(listener: () => void): () => void {
    listeners.add(listener);

    // Another tab changed the list.
    const sync = (event: StorageEvent) => {
        if (event.key === KEY) {
            cache = null;
            listener();
        }
    };
    window.addEventListener('storage', sync);

    return () => {
        listeners.delete(listener);
        window.removeEventListener('storage', sync);
    };
}

const EMPTY: number[] = [];

/**
 * Favorite product ids, kept in this browser so they work without an account.
 * The newest favorite comes first.
 */
export function useFavorites(): number[] {
    return useSyncExternalStore(subscribe, read, () => EMPTY);
}

export function isFavorite(ids: number[], id: number): boolean {
    return ids.includes(id);
}

const REMOVED_KEY = 'store.favorites.removed';
let accountId: number | null = null;
let syncTimer: ReturnType<typeof setTimeout> | null = null;

function removedIds(): number[] {
    try {
        const parsed: unknown = JSON.parse(
            localStorage.getItem(REMOVED_KEY) ?? '[]',
        );

        return Array.isArray(parsed)
            ? parsed.filter((id): id is number => Number.isInteger(id))
            : [];
    } catch {
        return [];
    }
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function pushToAccount(): Promise<void> {
    const removed = removedIds();
    const response = await fetch('/account/favorites/sync', {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify({ ids: read(), removed }),
    });

    if (!response.ok) {
        return;
    }

    const data = (await response.json()) as { ids: number[] };
    localStorage.removeItem(REMOVED_KEY);
    write(data.ids);
}

/**
 * For a signed-in customer, merges this browser's favorites with the account's
 * once per visit, then keeps the account updated after each change.
 */
export function syncFavoritesWith(userId: number | null): void {
    if (userId === null || userId === accountId) {
        accountId = userId;

        return;
    }

    accountId = userId;
    void pushToAccount().catch(() => undefined);
}

function scheduleSync(): void {
    if (accountId === null) {
        return;
    }

    if (syncTimer) {
        clearTimeout(syncTimer);
    }

    syncTimer = setTimeout(
        () => void pushToAccount().catch(() => undefined),
        800,
    );
}

/** Returns true when the product is now a favorite. */
export function toggleFavorite(id: number): boolean {
    const ids = read();

    if (ids.includes(id)) {
        write(ids.filter((saved) => saved !== id));

        if (accountId !== null) {
            try {
                localStorage.setItem(
                    REMOVED_KEY,
                    JSON.stringify([...removedIds(), id].slice(-60)),
                );
            } catch {
                // The account keeps it until the next removal syncs.
            }
        }

        scheduleSync();

        return false;
    }

    write([id, ...ids]);
    scheduleSync();

    return true;
}

/** Drops ids the store no longer sells, so the counter matches the page. */
export function keepFavorites(available: number[]): void {
    const ids = read();
    const kept = ids.filter((id) => available.includes(id));

    if (kept.length !== ids.length) {
        write(kept);
    }
}
