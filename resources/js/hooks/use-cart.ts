import { router } from '@inertiajs/react';
import { useSyncExternalStore } from 'react';
import { toast } from 'sonner';

const listeners = new Set<() => void>();
let drawerOpen = false;

function setDrawerOpen(open: boolean): void {
    drawerOpen = open;
    listeners.forEach((listener) => listener());
}

export const openCartDrawer = (): void => setDrawerOpen(true);
export const closeCartDrawer = (): void => setDrawerOpen(false);

export function useCartDrawerOpen(): boolean {
    return useSyncExternalStore(
        (listener) => {
            listeners.add(listener);

            return () => listeners.delete(listener);
        },
        () => drawerOpen,
        () => false,
    );
}

const cartOptions = {
    preserveScroll: true,
    preserveState: true,
    only: ['cart', 'flash', 'errors'],
    onError: (errors: Record<string, string>) => {
        toast.error(Object.values(errors)[0]);
    },
};

/**
 * Flies a copy of the product photo to the cart icon. Purely decorative, so it
 * never delays the request and is skipped for reduced-motion users.
 */
function flyToCart(image: HTMLElement | null | undefined): void {
    const target = [
        ...document.querySelectorAll<HTMLElement>('[data-cart-target]'),
    ].find((element) => element.offsetParent !== null);

    if (
        !(image instanceof HTMLImageElement) ||
        !target ||
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
    ) {
        return;
    }

    const from = image.getBoundingClientRect();
    const to = target.getBoundingClientRect();
    const clone = image.cloneNode() as HTMLImageElement;

    Object.assign(clone.style, {
        position: 'fixed',
        left: `${from.left}px`,
        top: `${from.top}px`,
        width: `${from.width}px`,
        height: `${from.height}px`,
        objectFit: 'contain',
        pointerEvents: 'none',
        zIndex: '60',
    });
    document.body.append(clone);

    void clone
        .animate(
            [
                { transform: 'none', opacity: 1 },
                {
                    transform: `translate(${to.left - from.left}px, ${to.top - from.top}px) scale(0.1)`,
                    opacity: 0.3,
                },
            ],
            { duration: 400, easing: 'ease-in' },
        )
        .finished.finally(() => clone.remove());
}

export function addToCart(
    productId: number,
    quantity = 1,
    {
        image,
        goToCart,
        colorId,
        onFinish,
    }: {
        image?: HTMLElement | null;
        goToCart?: string;
        colorId?: number | null;
        onFinish?: () => void;
    } = {},
): void {
    if (!goToCart) {
        flyToCart(image);
    }

    router.post(
        '/cart/items',
        { product_id: productId, quantity, color_id: colorId ?? null },
        {
            ...cartOptions,
            onSuccess: () => {
                if (goToCart) {
                    router.visit(goToCart);
                } else {
                    openCartDrawer();
                }
            },
            onFinish,
        },
    );
}

/** A line is a product in one color, so the color travels with the product id. */
function lineUrl(productId: number, colorId?: number | null): string {
    return colorId
        ? `/cart/items/${productId}?color=${colorId}`
        : `/cart/items/${productId}`;
}

export function updateCartLine(
    productId: number,
    quantity: number,
    colorId?: number | null,
): void {
    router.patch(lineUrl(productId, colorId), { quantity }, cartOptions);
}

export function removeCartLine(
    productId: number,
    colorId?: number | null,
): void {
    router.delete(lineUrl(productId, colorId), cartOptions);
}
