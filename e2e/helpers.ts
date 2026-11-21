import { expect } from '@playwright/test';
import type { Page } from '@playwright/test';

export const PASSWORD = 'E2e-password-123!';

/** Fails the test on any JavaScript error or failed request from this site. */
export function watchErrors(page: Page): string[] {
    const errors: string[] = [];

    page.on('pageerror', (error) =>
        errors.push(`page error: ${error.message}`),
    );
    page.on('console', (message) => {
        // 409 is how Inertia sends the browser to another site (the payment page).
        if (
            message.type() === 'error' &&
            !message.text().includes('favicon') &&
            !message.text().includes('status of 409')
        ) {
            errors.push(`console: ${message.text()}`);
        }
    });
    page.on('response', (response) => {
        const url = response.url();

        if (response.status() >= 500 && url.startsWith('http://127.0.0.1')) {
            errors.push(`HTTP ${response.status()}: ${url}`);
        }
    });

    return errors;
}

export async function signIn(
    page: Page,
    email: string,
    staff = false,
): Promise<void> {
    await page.goto(staff ? '/admin/login' : '/login');
    await page.locator('input[type="email"]').fill(email);
    await page.locator('input[type="password"]').fill(PASSWORD);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL((url) => !url.pathname.endsWith('/login'));
}

export async function noErrors(errors: string[]): Promise<void> {
    expect(errors, errors.join('\n')).toEqual([]);
}
