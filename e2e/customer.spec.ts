import { expect, test } from '@playwright/test';
import { noErrors, signIn, watchErrors } from './helpers';

test('a customer who received a product reviews it, and can order again', async ({
    page,
}) => {
    const errors = watchErrors(page);
    await signIn(page, 'sara@e2e.test');

    await page.goto('/ar/products/car-charger-ar');
    await page.getByRole('radio', { name: '4 نجوم' }).click();
    await page
        .getByPlaceholder('ما رأيك؟ (اختياري)')
        .fill('Works well in the car');
    await page.getByRole('button', { name: 'إرسال التقييم' }).click();
    await expect(page.getByText('بانتظار الموافقة قبل عرضه.')).toBeVisible();

    await page.goto('/ar/orders/E2EDONE1');
    await page.getByRole('button', { name: 'اطلب مجددًا' }).click();
    await expect(page).toHaveURL(/\/ar\/cart$/);
    await expect(page.getByText('شاحن سيارة سريع').first()).toBeVisible();
    // The last delivery address is filled in.
    await expect(page.getByLabel('العنوان')).toHaveValue('Haifa');
    await noErrors(errors);
});
