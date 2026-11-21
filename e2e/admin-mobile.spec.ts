import { expect, test } from '@playwright/test';
import { signIn, watchErrors } from './helpers';

// Staff run the store from their phones too. On a phone no admin page may be
// wider than the screen (a sideways scroll hides prices and buttons), and the
// list tables turn into cards whose buttons are in view.
test('every admin page fits a phone screen', async ({ page }) => {
    test.setTimeout(240_000);
    const errors = watchErrors(page);
    await signIn(page, 'owner@e2e.test', true);
    await page.goto('/admin/dashboard');

    const links = await page
        .locator('aside nav a[href^="/admin"]')
        .evaluateAll((anchors) => [
            ...new Set(anchors.map((a) => a.getAttribute('href') as string)),
        ]);
    await page.goto('/admin/orders');
    const order = await page
        .locator('main a[href^="/admin/orders/"]')
        .first()
        .getAttribute('href');
    const pages = [
        ...links,
        '/admin/products/create',
        '/admin/modules/coupons/create',
        ...(order ? [order] : []),
    ];

    for (const href of pages) {
        await page.goto(href);
        await page.waitForLoadState('networkidle');
        const width = await page.evaluate(() => ({
            page: document.documentElement.scrollWidth,
            screen: document.documentElement.clientWidth,
        }));
        expect(
            width.page,
            `${href} is wider than the screen`,
        ).toBeLessThanOrEqual(width.screen);
    }

    // A product row: its edit button is on screen, not behind a scroll.
    await page.goto('/admin/products');
    await expect(
        page
            .locator('table.admin-table')
            .getByRole('link', { name: 'تعديل' })
            .first(),
    ).toBeInViewport();

    expect(errors, errors.join('\n')).toEqual([]);
});

// On a phone, staff move between the store and the admin without hunting for
// a way back: the staff bar edits what they are looking at, and both sides
// have a bottom tab to the other.
test('staff go between the store and the admin on a phone', async ({
    page,
}) => {
    const errors = watchErrors(page);
    await signIn(page, 'owner@e2e.test', true);

    // Admin → store from the bottom bar.
    await page.goto('/admin/dashboard');
    const adminTabs = page.locator('[data-admin-tabs]');
    await expect(adminTabs).toBeInViewport();
    await adminTabs.getByRole('link', { name: 'المتجر' }).click();
    await page.waitForURL(/\/ar$/);

    // A product in the store: the staff bar opens it in the admin.
    await page.goto('/ar/products');
    await page.locator('main a[href^="/ar/products/"]').first().click();
    const staffBar = page.locator('[data-staff-bar]');
    await expect(staffBar).toBeInViewport();
    await staffBar.getByRole('link', { name: 'تعديل المنتج' }).click();
    await page.waitForURL(/\/admin\/products\/\d+\/edit/);

    // Back to the store from the product's form, then to the admin from the tab bar.
    await page.getByRole('link', { name: 'عرض في المتجر' }).click();
    await page.waitForURL(/\/ar\/products\//);
    await page.locator('nav').getByRole('link', { name: 'الإدارة' }).click();
    await page.waitForURL(/\/admin\//);

    // An order's line totals are on screen, not behind a sideways scroll.
    await page.goto('/admin/orders');
    await page.locator('main a[href^="/admin/orders/"]').first().click();
    await expect(page.getByRole('heading', { level: 1 })).toContainText(
        'الطلب',
    );
    await expect(
        page.locator('table.admin-table td[data-label="الإجمالي"]').first(),
    ).toBeInViewport();

    expect(errors, errors.join('\n')).toEqual([]);
});
