import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test('real dashboard session, RTL search, mobile reflow, dark mode and accessibility', async ({ page }, testInfo) => {
    const errors = [];
    page.on('pageerror', error => { errors.push(error.message); console.error(error.message); });
    page.on('console', message => { if (message.type() === 'error') console.error(message.text()); });
    await page.goto('/operations/students');
    await expect(page.getByRole('heading', { name: 'الطلبة', exact: true })).toBeVisible();
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    const globalSearch = page.getByRole('searchbox', { name: 'بحث عام', exact: true });
    await globalSearch.fill('client@dashboard.example.test');
    await expect(page.getByRole('link', { name: 'حساب المتصفح client', exact: true })).toBeVisible();
    await page.keyboard.press('Escape');
    const search = page.locator('.fi-ta-search-field input');
    await search.fill('notfound@example.test');
    await expect(page.getByText('لا يوجد طلبة', { exact: true })).toBeVisible();
    await search.fill('client@dashboard.example.test');
    await expect(page.getByRole('table', { name: 'الطلبة' }).getByText('client@dashboard.example.test', { exact: true })).toBeVisible();
    for (const width of [360, 390, 768, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await page.evaluate(() => document.fonts.ready);
        if (width >= 1024) {
            const label = page.getByRole('navigation', { name: 'القائمة الجانبية' }).getByRole('link', { name: 'الطلبة', exact: true }).locator('.fi-sidebar-item-label');
            await expect(label).toBeVisible();
            await expect(label).toHaveCSS('opacity', '1');
        }
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
        await page.screenshot({ path: testInfo.outputPath(`students-${width}.png`), fullPage: true });
    }
    const audit = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
    expect(audit.violations).toEqual([]);
    await page.getByRole('button', { name: 'قائمة المستخدم' }).click();
    await page.getByRole('menuitemradio', { name: 'تفعيل الوضع الليلي' }).click();
    await expect(page.locator('html')).toHaveClass(/dark/);
    await page.reload();
    await expect(page.locator('html')).toHaveClass(/dark/);
    await page.screenshot({ path: testInfo.outputPath('students-dark.png'), fullPage: true });
    expect(errors).toEqual([]);
});

test('path creation and editing use the actual responsive schema', async ({ page }, testInfo) => {
    const name = `مسار تجربة المتصفح ${Date.now()}`;
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/operations/paths/create');
    await page.getByLabel(/الاسم بالعربية/).fill(name);
    await page.getByLabel(/الاسم بالإنجليزية/).fill('Browser workflow');
    await page.screenshot({ path: testInfo.outputPath('path-create-phone.png'), fullPage: true });
    await page.getByRole('button', { name: 'إضافة', exact: true }).click();
    await page.waitForURL('**/operations/paths');
    const row = page.getByRole('row').filter({ hasText: name });
    await row.getByRole('link', { name: 'تعديل', exact: true }).click();
    await page.getByLabel(/الاسم بالعربية/).fill(`${name} المعتمد`);
    await page.getByRole('button', { name: 'حفظ التغييرات', exact: true }).click();
    await expect(page.getByText('تم الحفظ', { exact: true })).toBeVisible();
    await page.reload();
    await expect(page.getByLabel(/الاسم بالعربية/)).toHaveValue(`${name} المعتمد`);
    const audit = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
    expect(audit.violations).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    await page.screenshot({ path: testInfo.outputPath('path-edit-phone.png'), fullPage: true });
});
