import { useSyncExternalStore } from 'react';

const KEY = 'store.compare';
export const MAX_COMPARE = 4;
const listeners = new Set<() => void>();
let cache: number[] | null = null;

function read(): number[] {
    if (cache !== null) {
        return cache;
    }

    try {
        const parsed: unknown = JSON.parse(localStorage.getItem(KEY) ?? '[]');
        cache = Array.isArray(parsed)
            ? parsed
                  .filter((id): id is number => Number.isInteger(id) && id > 0)
                  .slice(0, MAX_COMPARE)
            : [];
    } catch {
        cache = [];
    }

    return cache;
}

function write(ids: number[]): void {
    cache = ids.slice(0, MAX_COMPARE);

    try {
        localStorage.setItem(KEY, JSON.stringify(cache));
    } catch {
        // Private mode: the list lasts until the tab closes.
    }

    listeners.forEach((listener) => listener());
}

function subscribe(listener: () => void): () => void {
    listeners.add(listener);
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

/** Products picked for comparison in this browser, at most four, in the order picked. */
export function useCompare(): number[] {
    return useSyncExternalStore(subscribe, read, () => EMPTY);
}

/** Returns false when the list is already full. */
export function toggleCompare(id: number): boolean {
    const ids = read();

    if (ids.includes(id)) {
        write(ids.filter((saved) => saved !== id));

        return true;
    }

    if (ids.length >= MAX_COMPARE) {
        return false;
    }

    write([...ids, id]);

    return true;
}

export function clearCompare(): void {
    write([]);
}
