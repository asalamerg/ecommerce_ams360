import { expect, test } from '@playwright/test';
import { noErrors, watchErrors } from './helpers';

// Desktop and phone (see playwright.config.ts).

test('home shows main sections, and the brands menu stays open while pointing', async ({
    page,
}) => {
    const errors = watchErrors(page);
    await page.goto('/ar');
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');

    const button = page.getByRole('button', { name: 'العلامات التجارية' });
    await button.click();
    const panel = page.locator('section[aria-label="العلامات التجارية"]');
    const tiles = panel.locator('ul li a');
    await expect(tiles).toHaveCount(2);

    // Pointing at a brand prefetches its page and must not close the panel.
    await tiles.first().hover();
    await page.waitForTimeout(400);
    await expect(panel).toBeVisible();

    // Other spellings find the brand.
    await panel.locator('input[type="search"]').fill('باسيوس');
    await expect(tiles).toHaveCount(1);

    await tiles.first().click();
    await expect(page).toHaveURL(/\/ar\/brands\/baseus$/);
    await expect(panel).toBeHidden();
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('بيسوس');
    await noErrors(errors);
});

test('a main section lists its categories and their products', async ({
    page,
}) => {
    const errors = watchErrors(page);
    await page.goto('/ar/categories/chargers-ar');
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('شواحن');
    const main = page.locator('main');
    await expect(main.getByRole('link', { name: 'شواحن سيارة' })).toBeVisible();
    await expect(
        main.getByRole('link', { name: 'شواحن جدارية' }),
    ).toBeVisible();
    await expect(
        page.locator('main article, main [href*="/ar/products/"]').first(),
    ).toBeVisible();

    await main.getByRole('link', { name: 'شواحن سيارة' }).click();
    await expect(page).toHaveURL(/car-chargers-ar/);
    // The breadcrumb leads back to the main section.
    await expect(
        page.locator('nav').getByRole('link', { name: 'شواحن', exact: true }),
    ).toBeVisible();
    await noErrors(errors);
});

test('search by another spelling, then compare two products', async ({
    page,
}) => {
    const errors = watchErrors(page);
    await page.goto(`/ar/products?search=${encodeURIComponent('باسيوس')}`);
    await expect(page.getByText('شاحن سيارة سريع').first()).toBeVisible();

    for (const slug of ['car-charger-ar', 'wall-charger-ar']) {
        await page.goto(`/ar/products/${slug}`);
        await page.getByRole('button', { name: 'قارن', exact: true }).click();
        await expect(
            page.getByRole('button', { name: 'في المقارنة' }),
        ).toBeVisible();
    }

    await page.getByRole('link', { name: 'قارن الآن' }).click();
    await expect(page).toHaveURL(/\/ar\/compare$/);
    const table = page.locator('table');
    await expect(table).toContainText('شاحن سيارة سريع');
    await expect(table).toContainText('شاحن جداري 65 واط');
    await expect(table).toContainText('بيسوس');
    await noErrors(errors);
});

test('an out-of-stock product offers a back-in-stock e-mail', async ({
    page,
}) => {
    const errors = watchErrors(page);
    await page.goto('/ar/products/power-bank-ar');
    await page.locator('input[type="email"]').fill('fan@e2e.test');
    await page.getByRole('button', { name: 'أبلغني' }).click();
    await expect(page.getByText('سنراسلك عند عودته إلى المخزون')).toBeVisible();
    await noErrors(errors);
});

test('contact us: send a message; repairs: send a request with a photo', async ({
    page,
}) => {
    const errors = watchErrors(page);
    await page.goto('/ar/contact');
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(
        'تواصل معنا',
    );
    await page.getByLabel('الاسم').fill('رامي');
    await page.getByLabel('رقم الهاتف').fill('052-111-2233');
    await page.getByLabel('رسالتك').fill('هل توصلون إلى جنين؟');
    await page.getByRole('button', { name: 'إرسال' }).click();
    await expect(page.getByText('وصلتنا رسالتك، شكرًا لك.')).toBeVisible();

    await page.goto('/ar/repairs');
    await page.getByLabel('نوع الجهاز').selectOption('tablet');
    await page.getByLabel('الموديل').fill('iPad Air');
    await page.getByLabel('صف المشكلة').fill('الشاشة لا تستجيب للمس');
    await page.getByLabel('رقم الهاتف').fill('0523334455');
    await page
        .getByLabel('صور الجهاز (اختياري)')
        .setInputFiles('e2e/fixtures/logo.png');
    await page.getByRole('button', { name: 'إرسال طلب الصيانة' }).click();
    await expect(page.getByText(/استلمنا طلبك رقم #\d+/)).toBeVisible();
    await noErrors(errors);
});

test('a product page offers WhatsApp with the product named', async ({
    page,
}) => {
    await page.goto('/ar/products/car-charger-ar');
    const link = page
        .getByRole('main')
        .getByRole('link', { name: 'اسألنا على واتساب' });
    await expect(link).toHaveAttribute(
        'href',
        new RegExp(encodeURIComponent('شاحن سيارة سريع')),
    );
});
