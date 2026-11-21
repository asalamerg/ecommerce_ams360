import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { PASSWORD, noErrors, signIn, watchErrors } from './helpers';

// One sign-in for the whole file: the login limit (5 a minute per account)
// is doing its job when a fresh sign-in before every test gets refused.
test.describe.configure({ mode: 'serial' });

let page: Page;

test.beforeAll(async ({ browser }) => {
    page = await browser.newPage({ viewport: { width: 1366, height: 860 } });
    await signIn(page, 'owner@e2e.test', true);
});

test.afterAll(async () => {
    await page.close();
});

test('orders: filter, open, note, status with history, invoice', async () => {
    const errors = watchErrors(page);
    await page.goto('/admin/orders');
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('الطلبات');

    await page.getByRole('link', { name: 'E2EDONE1' }).click();
    await expect(page).toHaveURL(/\/admin\/orders\/\d+$/);

    await page
        .getByPlaceholder('ملاحظة داخلية (لا يراها العميل أبدًا)')
        .fill('Checked by E2E');
    await page.getByRole('button', { name: 'أضف ملاحظة' }).click();
    await expect(page.getByText('Checked by E2E')).toBeVisible();

    const invoice = page.waitForEvent('popup');
    await page.getByRole('link', { name: 'طباعة الفاتورة' }).click();
    const invoicePage = await invoice;
    await expect(invoicePage.getByText('E2EDONE1')).toBeVisible();
    await expect(
        invoicePage.getByText('هذا ملخص طلب وليس فاتورة ضريبية.'),
    ).toBeVisible();
    await invoicePage.close();
    await noErrors(errors);
});

test('reports show the period, the chart and best sellers', async () => {
    const errors = watchErrors(page);
    await page.goto('/admin/reports');
    await expect(
        page.getByRole('heading', { name: 'المبيعات اليومية' }),
    ).toBeVisible();
    await expect(
        page.getByRole('heading', { name: 'الأكثر مبيعًا', exact: true }),
    ).toBeVisible();
    await expect(page.getByText('شاحن سيارة سريع')).toBeVisible();
    await noErrors(errors);
});

test('products: select several and hide them, then show them again', async () => {
    const errors = watchErrors(page);
    await page.goto('/admin/products');
    const rows = page.locator('tbody tr');
    await rows.nth(0).locator('input[type="checkbox"]').check();
    await rows.nth(1).locator('input[type="checkbox"]').check();
    await page.getByRole('button', { name: 'إخفاء من المتجر' }).click();
    await expect(page.getByText('حُدّث 2 منتج.')).toBeVisible();

    await rows.nth(0).locator('input[type="checkbox"]').check();
    await rows.nth(1).locator('input[type="checkbox"]').check();
    await page.getByRole('button', { name: 'إظهار في المتجر' }).click();
    await expect(page.getByText('حُدّث 2 منتج.')).toBeVisible();
    await noErrors(errors);
});

test('a category can be placed under a main section', async () => {
    const errors = watchErrors(page);
    await page.goto('/admin/categories');
    await expect(page.getByText(/قسم رئيسي يضم \d+ أقسام/)).toBeVisible();
    await expect(page.getByText('ضمن شواحن').first()).toBeVisible();
    await noErrors(errors);
});

test('settings are in Arabic, right to left', async () => {
    const errors = watchErrors(page);
    await page.goto('/settings/profile');
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page.getByText('غيّر اسمك وبريدك الإلكتروني')).toBeVisible();
    // Security settings ask for the password again first.
    await page.goto('/settings/security');
    if (page.url().includes('confirm-password')) {
        await page.getByLabel('كلمة المرور', { exact: true }).fill(PASSWORD);
        await page.getByRole('button', { name: 'تأكيد', exact: true }).click();
    }
    await expect(page).toHaveURL(/\/settings\/security/);
    await expect(page.getByText('تغيير كلمة المرور').first()).toBeVisible();
    await noErrors(errors);
});

test('a logo dropped on a brand appears in the storefront brand menu', async () => {
    const errors = watchErrors(page);
    await page.goto('/admin/brands/logos');
    await expect(page.getByText('0 من 2 علامة لها شعار')).toBeVisible();

    const card = page.locator('li').filter({ hasText: 'انكر' });
    await card
        .locator('input[type="file"]')
        .setInputFiles('e2e/fixtures/logo.png');
    await expect(page.getByText('حُفظ شعار انكر.')).toBeVisible();
    await expect(page.getByText('1 من 2 علامة لها شعار')).toBeVisible();

    await page.goto('/ar');
    await page.getByRole('button', { name: 'العلامات التجارية' }).click();
    const tile = page
        .locator('section[aria-label="العلامات التجارية"] li')
        .filter({ hasText: 'انكر' });
    await expect(tile.locator('img')).toHaveAttribute(
        'src',
        /\/storage\/uploads\/brands\/.+\.webp/,
    );
    await noErrors(errors);
});
