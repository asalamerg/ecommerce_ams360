import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { noErrors, watchErrors } from './helpers';

async function addToCart(page: Page, slug: string): Promise<void> {
    await page.goto(`/ar/products/${slug}`);
    await page.getByRole('button', { name: 'أضف إلى السلة' }).first().click();
    await page.waitForLoadState('networkidle');
}

async function fillDetails(page: Page): Promise<void> {
    await page.goto('/ar/cart');
    await page.getByLabel('الاسم الكامل').fill('E2E Guest');
    await page.getByLabel('رقم الهاتف').fill('0529998877');
}

test('a guest pays online for delivery and the order is confirmed', async ({
    page,
}) => {
    const errors = watchErrors(page);
    await addToCart(page, 'wall-charger-ar');
    await fillDetails(page);

    await page.getByLabel('منطقة التوصيل').selectOption({ index: 1 });
    await page.getByLabel('العنوان').fill('Haifa, Herzl 10');
    // 149 + 30 delivery: below the free delivery line.
    await expect(page.locator('form aside')).toContainText('179');

    await page.getByRole('button', { name: 'ادفع الآن' }).click();
    await expect(page.getByText('TEST GATEWAY')).toBeVisible();
    await expect(page.getByText('179.00 ILS')).toBeVisible();
    await page.getByRole('button', { name: 'Approve payment' }).click();

    await expect(page).toHaveURL(/\/ar\/orders\/[A-Z0-9]{8}$/);
    await expect(page.getByText('تم استلام الدفع. شكرًا لك!')).toBeVisible();
    await noErrors(errors);
});

test('a declined card can be paid again from the order page', async ({
    page,
}) => {
    const errors = watchErrors(page);
    await addToCart(page, 'usb-cable-ar');
    await fillDetails(page);
    await page.getByLabel('منطقة التوصيل').selectOption({ index: 1 });
    await page.getByLabel('العنوان').fill('Akko');

    await page.getByRole('button', { name: 'ادفع الآن' }).click();
    await page.getByRole('button', { name: 'Decline' }).click();
    await expect(
        page.getByText('لم يتم الدفع. يمكنك المحاولة مجددًا.'),
    ).toBeVisible();

    await page.getByRole('button', { name: 'ادفع الآن' }).click();
    await page.getByRole('button', { name: 'Approve payment' }).click();
    await expect(page.getByText('تم استلام الدفع. شكرًا لك!')).toBeVisible();
    await noErrors(errors);
});

test('pickup from a branch through WhatsApp, then tracking from another browser', async ({
    page,
    browser,
}) => {
    const errors = watchErrors(page);
    await addToCart(page, 'car-charger-ar');
    await fillDetails(page);
    await page.getByText('استلام من الفرع').click();
    await page
        .locator('select')
        .filter({ hasText: 'اختر الفرع' })
        .selectOption({ index: 1 });
    await page.getByText('اتفق على الدفع عبر واتساب').click();

    const popup = page.waitForEvent('popup');
    await page.getByRole('button', { name: 'إتمام الطلب عبر واتساب' }).click();
    await (await popup).close();
    await expect(page).toHaveURL(/\/ar\/orders\/[A-Z0-9]{8}$/);
    const reference = page.url().split('/').pop() as string;
    await expect(page.getByText('استلام: فرع حيفا')).toBeVisible();

    // Another browser has no session: it needs the number and the phone.
    const other = await browser.newPage();
    await other.goto(`/ar/orders/${reference}`);
    await expect(other.getByText('404')).toBeVisible();
    await other.goto(`/ar/track?reference=${reference}`);
    await other.getByLabel('رقم الهاتف').fill('+972 52 999 8877');
    await other.getByRole('button', { name: 'اعرض طلبي' }).click();
    await expect(other).toHaveURL(new RegExp(`/ar/orders/${reference}$`));
    await expect(other.getByText('مراحل الطلب')).toBeVisible();
    await other.close();
    await noErrors(errors);
});
