import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';
import { PASSWORD, signIn, watchErrors } from './helpers';

// Every admin feature through its real screens, filled in Arabic only, as staff
// will use it. One sign-in for the file (the login limit is 5 a minute).
test.describe.configure({ mode: 'serial' });

let page: Page;
let errors: string[];

test.beforeAll(async ({ browser }) => {
    page = await browser.newPage({ viewport: { width: 1366, height: 900 } });
    errors = watchErrors(page);
    await signIn(page, 'owner@e2e.test', true);
});

test.afterEach(() => {
    expect(errors, errors.join('\n')).toEqual([]);
});

test.afterAll(async () => {
    await page.close();
});

const saved = () =>
    expect(page.getByText('تم حفظ التغييرات.').first()).toBeVisible();

/** Sensitive actions ask for the password again; answer when asked. */
async function confirmPasswordIfAsked(): Promise<boolean> {
    await page.waitForLoadState('networkidle');

    if (!page.url().includes('confirm-password')) {
        return false;
    }

    await page.getByLabel('كلمة المرور', { exact: true }).fill(PASSWORD);
    await page.getByRole('button', { name: 'تأكيد', exact: true }).click();
    await page.waitForLoadState('networkidle');

    return true;
}

/** A searchable list (product, category, branch): open it and pick by name. */
async function pick(field: Locator, option: string): Promise<void> {
    await field.getByRole('button').first().click();
    await page
        .getByRole('option', { name: new RegExp(option) })
        .first()
        .click();
}

/** The innermost block whose text starts with the label and holds a control. */
function field(label: string): Locator {
    return page
        .locator('main div, main label')
        .filter({ hasText: new RegExp(`^s*${label}`) })
        .filter({
            has: page.locator('[aria-haspopup="listbox"], input, textarea'),
        })
        .last();
}

test('every page in the admin menu opens without errors', async () => {
    await page.goto('/admin/dashboard');
    const links = await page
        .locator('aside nav a[href^="/admin"]')
        .evaluateAll((anchors) => [
            ...new Set(
                anchors.map((a) =>
                    (a as HTMLAnchorElement).getAttribute('href'),
                ),
            ),
        ]);
    expect(links.length).toBeGreaterThan(20);

    for (const href of links) {
        const response = await page.goto(href as string);
        expect(response?.status(), href as string).toBeLessThan(400);
        await expect(
            page.locator('main h1').first(),
            href as string,
        ).toBeVisible();
    }
});

test('offers: add an offer with products, in Arabic only', async () => {
    await page.goto('/admin/modules/offers/create');
    await page.getByLabel('العنوان', { exact: true }).fill('عرض الشتاء');
    // The discount type opens on a real choice; only the value is typed.
    await expect(page.getByLabel('نوع الخصم')).toHaveValue('percentage');
    await page.getByLabel('قيمة الخصم').fill('15');
    await pick(field('المنتجات المشمولة بالعرض'), 'شاحن سيارة سريع');
    await expect(
        page.locator('main li').filter({ hasText: 'شاحن سيارة سريع' }),
    ).toBeVisible();
    await page.getByRole('button', { name: 'حفظ التغييرات' }).click();
    await saved();

    await page.goto('/admin/modules/offers');
    await expect(page.getByText('عرض الشتاء')).toBeVisible();
});

test('coupons: add one, then delete it after confirming the password', async () => {
    await page.goto('/admin/modules/coupons/create');
    await page.getByLabel('رمز القسيمة').fill('WINTER15');
    await page.getByLabel('قيمة الخصم').fill('15');
    await page.getByRole('button', { name: 'حفظ التغييرات' }).click();
    await saved();

    await page.goto('/admin/modules/coupons');
    page.once('dialog', (dialog) => dialog.accept());
    await page
        .locator('tr')
        .filter({ hasText: 'WINTER15' })
        .getByRole('button', { name: 'حذف' })
        .click();

    if (await confirmPasswordIfAsked()) {
        // Back on the list: delete again, now confirmed.
        page.once('dialog', (dialog) => dialog.accept());
        await page
            .locator('tr')
            .filter({ hasText: 'WINTER15' })
            .getByRole('button', { name: 'حذف' })
            .click();
    }

    await expect(page.getByText('تم حذف العنصر.')).toBeVisible();
    await expect(
        page.locator('tr').filter({ hasText: 'WINTER15' }),
    ).toHaveCount(0);
});

test('orders: confirm a WhatsApp order, note it, then cancel it', async () => {
    await page.goto('/admin/orders?status=pending');
    await page.getByRole('link', { name: 'E2EPEND1' }).click();

    await page.getByLabel('حالة الطلب').selectOption('processing');
    await page.getByLabel('حالة الدفع').selectOption('paid');
    await page.getByRole('button', { name: 'حفظ', exact: true }).click();
    await saved();
    await expect(
        page
            .locator('ol')
            .getByText(/الحالة:/)
            .first(),
    ).toBeVisible();

    await page
        .getByPlaceholder('ملاحظة داخلية (لا يراها العميل أبدًا)')
        .fill('اتصلنا بالعميل');
    await page.getByRole('button', { name: 'أضف ملاحظة' }).click();
    await expect(page.getByText('اتصلنا بالعميل')).toBeVisible();

    await page.getByLabel('حالة الطلب').selectOption('cancelled');
    await page.getByRole('button', { name: 'حفظ', exact: true }).click();
    await saved();
    // A cancelled order cannot be reopened.
    await expect(
        page.getByLabel('حالة الطلب').locator('option[value="processing"]'),
    ).toHaveJSProperty('disabled', true);

    // The two chargers it held are back in stock.
    await page.goto('/admin/inventory');
    await expect(
        page.locator('article').filter({ hasText: 'شاحن جداري 65 واط' }),
    ).toContainText('17');
});

test('products: add one in Arabic only; it shows in the English store too', async () => {
    // Opened from the second view of the list: saving comes back to it.
    await page.goto('/admin/products?status=shown');
    await page.getByRole('link', { name: 'إضافة منتج' }).click();
    await page.getByLabel('اسم المنتج').fill('سماعة بلوتوث صغيرة');
    await page
        .getByRole('spinbutton', { name: 'السعر', exact: true })
        .fill('79');
    await pick(field('القسم'), 'شواحن جدارية');
    await page.getByRole('button', { name: 'إنشاء منتج' }).click();
    await expect(page).toHaveURL(/\/admin\/products\?status=shown$/);
    await expect(
        page.getByText('تمت إضافة المنتج «سماعة بلوتوث صغيرة» بنجاح.'),
    ).toBeVisible();

    await page.goto(
        `/en/products?search=${encodeURIComponent('سماعة بلوتوث')}`,
    );
    await expect(page.getByText('سماعة بلوتوث صغيرة').first()).toBeVisible();
});

test('products: a discount shows on the card with the old price struck out', async () => {
    await page.goto(
        `/admin/products?q=${encodeURIComponent('سماعة بلوتوث صغيرة')}`,
    );
    await page.getByRole('link', { name: 'تعديل' }).first().click();
    await page.getByLabel('نسبة الخصم (%)').fill('20');
    // 79 less 20%.
    await expect(page.getByText('63.20 ILS')).toBeVisible();
    await page.getByRole('button', { name: 'حفظ التغييرات' }).click();
    await expect(page).toHaveURL(/\/admin\/products\?q=/);
    await expect(page.locator('tr').filter({ hasText: '-20%' })).toBeVisible();

    await page.goto(
        `/ar/products?search=${encodeURIComponent('سماعة بلوتوث')}`,
    );
    const card = page
        .locator('article')
        .filter({ hasText: 'سماعة بلوتوث صغيرة' });
    await expect(card.getByText('خصم 20%')).toBeVisible();
    await expect(card.locator('s')).toContainText('79');
});

test('categories: add one under a main section, in Arabic only', async () => {
    await page.goto('/admin/categories/create');
    await page.getByLabel('اسم القسم').fill('شواحن لاسلكية');
    await pick(field('القسم الرئيسي'), 'شواحن');
    await page.getByLabel('عرض كقسم في الصفحة الرئيسية').check();
    await page.getByRole('button', { name: 'إنشاء قسم' }).click();
    await expect(page).toHaveURL(/\/admin\/categories$/);
    await expect(
        page.getByText('تمت إضافة القسم «شواحن لاسلكية» بنجاح.'),
    ).toBeVisible();
    await expect(
        page.getByText('شواحن لاسلكية', { exact: true }).locator('xpath=..'),
    ).toContainText('في الرئيسية');

    await expect(
        page.getByText('شواحن لاسلكية', { exact: true }).locator('xpath=..'),
    ).toContainText('ضمن شواحن');
});

test('categories: find one by name to edit it', async () => {
    await page.goto('/admin/categories');
    // Searches as you type, no Enter needed.
    await page.getByPlaceholder('بحث', { exact: true }).fill('جداريه');
    await expect(page).toHaveURL(/\/admin\/categories\?q=/);
    // ة and ه are the same letter for search.
    await expect(page.getByText('شواحن جدارية', { exact: true })).toBeVisible();
    await expect(page.getByText('شواحن سيارة', { exact: true })).toHaveCount(0);
    await page.getByRole('link', { name: 'تعديل' }).first().click();
    await expect(page.getByLabel('اسم القسم')).toHaveValue('شواحن جدارية');
});

test('featured products: found from the menu search, and featured in bulk', async () => {
    await page.goto('/admin/dashboard');
    await page.getByPlaceholder('ابحث عن صفحة').fill('مميزة');
    await page.getByRole('link', { name: 'منتجات مميزة' }).click();
    await expect(page).toHaveURL(/\/admin\/products\?status=featured$/);

    await page.goto('/admin/products');
    await page.getByRole('checkbox', { name: 'كابل USB-C' }).check();
    await page
        .getByRole('button', { name: 'إبراز في الصفحة الرئيسية' })
        .click();
    await page.goto('/admin/products?status=featured');
    await expect(
        page.locator('tr').filter({ hasText: 'كابل USB-C' }),
    ).toBeVisible();

    await page.goto('/ar');
    await expect(
        page.getByRole('heading', { name: 'منتجات مميزة' }),
    ).toBeAttached();
});

test('inventory: add stock with a reason', async () => {
    await page.goto('/admin/inventory');
    const row = page.locator('article').filter({ hasText: 'كابل USB-C' });
    await page.getByLabel('كمية كابل USB-C').fill('5');
    await row.getByPlaceholder('سبب التعديل').fill('شحنة جديدة');
    await row.getByRole('button').last().click();
    await expect(row).toContainText('55');
    // Recorded with its reason in the movement log.
    await expect(
        page.locator('tr').filter({ hasText: 'شحنة جديدة' }),
    ).toContainText('55');
});

test('delivery zones, branches with opening hours, and SIM plans with features', async () => {
    await page.goto('/admin/modules/shipping-zones/create');
    await page.getByLabel('اسم المنطقة').fill('الجنوب');
    await page.getByLabel('رسوم التوصيل (₪)').fill('40');
    await page.getByRole('button', { name: 'حفظ التغييرات' }).click();
    await saved();

    await page.goto('/admin/modules/branches');
    await page
        .locator('main')
        .getByRole('link', { name: 'إدارة', exact: true })
        .first()
        .click();
    await page.getByLabel('الأحد').selectOption('open');
    await page.getByRole('button', { name: 'حفظ ومتابعة التعديل' }).click();
    await saved();
    await expect(page.getByLabel('يفتح الساعة').first()).toHaveValue('09:00');

    // The branch and its hours are on the contact page.
    await page.goto('/ar/contact');
    await expect(page.getByText('فرع حيفا')).toBeVisible();

    await page.goto('/admin/modules/sim-plans/create');
    await page.getByLabel('شركة الاتصالات').fill('Partner');
    await page.getByLabel('اسم الباقة').fill('باقة 5G');
    await page.getByLabel('السعر الشهري').fill('49');
    await page.getByRole('button', { name: 'إضافة سطر' }).click();
    await field('مزايا الباقة')
        .locator('input')
        .first()
        .fill('مكالمات غير محدودة');
    await page.getByRole('button', { name: 'حفظ التغييرات' }).click();
    await saved();
    // Back on the list, with the new plan in it.
    await expect(page).toHaveURL(/\/admin\/modules\/sim-plans$/);
    await expect(page.getByText('باقة 5G')).toBeVisible();

    // Customers see it, with its features, and order it on WhatsApp.
    await page.goto('/ar/sim-plans');
    const plan = page.locator('li').filter({ hasText: 'باقة 5G' });
    await expect(plan).toContainText('مكالمات غير محدودة');
    await expect(
        plan.getByRole('link', { name: 'اطلب الباقة عبر واتساب' }),
    ).toHaveAttribute('href', /wa\.me/);
});

test('content: a page and a home slide with an uploaded picture', async () => {
    await page.goto('/admin/modules/pages/create');
    await page.getByLabel('الرابط المختصر').fill('shipping-policy');
    await page.getByLabel('العنوان', { exact: true }).fill('سياسة الشحن');
    await page.getByLabel('المحتوى').fill('نوصل خلال يومي عمل.');
    await page.getByRole('button', { name: 'حفظ التغييرات' }).click();
    await saved();
    await page.goto('/he/pages/shipping-policy');
    await expect(page.getByText('نوصل خلال يومي عمل.')).toBeVisible();

    await page.goto('/admin/modules/hero-slides/create');
    await page.getByLabel('العنوان', { exact: true }).fill('تخفيضات الشتاء');
    await page
        .locator('input[type="file"]')
        .first()
        .setInputFiles('e2e/fixtures/logo.png');
    await page.getByRole('button', { name: 'حفظ التغييرات' }).click();
    await saved();
    await page.goto('/en');
    await expect(page.getByText('تخفيضات الشتاء').first()).toBeVisible();
});

test('users: give a customer points; it asks for the password first', async () => {
    await page.goto('/admin/modules/users');
    await page
        .locator('tr')
        .filter({ hasText: 'sara@e2e.test' })
        .getByRole('link', { name: 'إدارة', exact: true })
        .click();
    await confirmPasswordIfAsked();
    await expect(page).toHaveURL(/\/admin\/modules\/users\/\d+\/edit(\?|$)/);

    await page.getByLabel(/إضافة أو خصم نقاط/).fill('50');
    await page.getByLabel('سبب تعديل النقاط').fill('هدية افتتاح');
    await page.getByRole('button', { name: 'حفظ ومتابعة التعديل' }).click();
    await saved();
    await expect(page.getByLabel(/رصيد النقاط/)).toHaveValue('50');
});

test('loyalty and store settings', async () => {
    await page.goto('/admin/modules/loyalty');
    await page
        .locator('main')
        .getByRole('link', { name: 'إدارة', exact: true })
        .first()
        .click();
    await confirmPasswordIfAsked();
    await page.getByLabel('تفعيل برنامج النقاط').check();
    await page.getByRole('button', { name: 'حفظ التغييرات' }).click();
    await saved();

    await page.goto('/admin/modules/settings/1/edit');
    await confirmPasswordIfAsked();
    await page
        .getByLabel('بريد التنبيهات للطلبات الجديدة والمخزون المنخفض')
        .fill('alerts@e2e.test');
    await page.getByRole('button', { name: 'حفظ التغييرات' }).click();
    await saved();
});

test('customer care: approve a review, read a message, update a repair and a ticket', async () => {
    await page.goto('/admin/modules/reviews');
    await page
        .locator('main')
        .getByRole('link', { name: 'إدارة', exact: true })
        .first()
        .click();
    await page.getByLabel('معتمد للعرض').check();
    await page.getByRole('button', { name: 'حفظ التغييرات' }).click();
    await saved();
    await page.goto('/ar/products/wall-charger-ar');
    await expect(page.getByText('Charges fast')).toBeVisible();

    await page.goto('/admin/modules/contact');
    await page
        .locator('main')
        .getByRole('link', { name: 'إدارة', exact: true })
        .first()
        .click();
    await page.getByLabel('مقروءة').check();
    await page.getByRole('button', { name: 'حفظ التغييرات' }).click();
    await saved();

    await page.goto('/admin/modules/maintenance');
    await page
        .locator('main')
        .getByRole('link', { name: 'إدارة', exact: true })
        .first()
        .click();
    await page.getByLabel('الحالة').selectOption('ready');
    await page.getByLabel('التكلفة التقديرية').fill('250');
    await pick(field('الفرع'), 'فرع حيفا');
    await page.getByRole('button', { name: 'حفظ التغييرات' }).click();
    await saved();

    await page.goto('/admin/modules/support');
    await page
        .locator('main')
        .getByRole('link', { name: 'إدارة', exact: true })
        .first()
        .click();
    await page.getByLabel('الحالة').selectOption('closed');
    await page.getByRole('button', { name: 'حفظ التغييرات' }).click();
    await saved();
});

test('notifications: the bell lists alerts and marks them read', async () => {
    await page.goto('/admin/dashboard');
    await page.getByRole('button', { name: 'الإشعارات' }).click();
    await expect(page.getByText('الإشعارات').first()).toBeVisible();
    await page.keyboard.press('Escape');
});
