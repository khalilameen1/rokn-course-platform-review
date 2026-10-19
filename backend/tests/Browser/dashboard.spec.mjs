import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test('financial table shows actual states and keeps search scope in CSV on a phone', async ({ page }, testInfo) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/operations/orders');
    await page.locator('.fi-ta-search-field input').fill('BROWSER-PAID-900');
    const table = page.getByRole('table', { name: 'الطلبات', exact: true });
    await expect(table.getByText('11.11 EGP', { exact: true })).toBeVisible();
    await expect(table.getByText('الصافي بانتظار التسوية', { exact: true })).toBeVisible();
    await expect(table.getByText('مدفوع', { exact: true })).toBeInViewport();
    await expect(table.getByText('اختبار خارج الإيراد', { exact: true })).toBeInViewport();
    await expect(table.getByText('11.11 EGP', { exact: true })).toBeInViewport();
    const exportLink = page.getByRole('link', { name: 'تصدير CSV', exact: true });
    await expect(exportLink).toHaveAttribute('href', /search=BROWSER-PAID-900/);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    await page.screenshot({ path: testInfo.outputPath('orders-phone.png'), fullPage: true });
    await page.getByRole('button', { name: 'تصفية', exact: true }).click();
    await expect(page.getByLabel('أعلى مبلغ', { exact: true })).toBeVisible();
    await expect(page.locator('.fi-modal-window').filter({ has: page.getByLabel('أعلى مبلغ', { exact: true }) })).toHaveCSS('opacity', '1');
    await page.screenshot({ path: testInfo.outputPath('orders-filters-phone.png'), fullPage: true });
    const audit = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
    expect(audit.violations).toEqual([]);
    await page.getByLabel('أعلى مبلغ', { exact: true }).fill('100');
    const applyFilters = page.getByRole('button', { name: 'تطبيق التصفيات', exact: true });
    await applyFilters.scrollIntoViewIfNeeded();
    await expect(applyFilters).toBeInViewport();
    await page.screenshot({ path: testInfo.outputPath('orders-filters-apply-phone.png'), fullPage: true });
    await applyFilters.click();
    await expect(page.getByLabel('أعلى مبلغ', { exact: true })).not.toBeVisible();
    await expect.poll(async () => new URL(await exportLink.getAttribute('href')).searchParams.get('amount_max')).toBe('100');
    await page.setViewportSize({ width: 1440, height: 900 });
    const navigationLabel = page.getByRole('navigation', { name: 'القائمة الجانبية' }).getByRole('link', { name: 'الطلبات', exact: true }).locator('.fi-sidebar-item-label');
    await expect(navigationLabel).toBeVisible();
    await expect(navigationLabel).toHaveCSS('opacity', '1');
    await expect(table.getByText('اختبار خارج الإيراد', { exact: true })).toBeVisible();
    await page.screenshot({ path: testInfo.outputPath('orders-desktop.png'), fullPage: true });
    expect(errors).toEqual([]);
});

test('native drafts survive refresh, retain receipt and clear after save', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('dialog', dialog => dialog.accept());
    const name = `مسودة المتصفح ${Date.now()}`;
    await page.goto('/operations/paths/create');
    await expect(page.locator('[data-authoring-draft]')).toHaveAttribute('data-draft-ready', 'true');
    await page.getByLabel(/الاسم بالعربية/).fill(name);
    await page.getByLabel(/الاسم بالإنجليزية/).fill('Recovered path');
    const drafts = () => page.evaluate(() => Object.entries(localStorage)
        .filter(([key]) => key.startsWith('rokn:course-authoring-draft:') && key.includes('/operations/paths/create:'))
        .map(([, value]) => JSON.parse(value)));
    await expect.poll(async () => (await drafts())[0]?.values.title_ar).toBe(name);
    const receipt = (await drafts())[0].receipt;
    await page.reload();
    await expect(page.getByLabel(/الاسم بالعربية/)).toHaveValue(name);
    expect((await drafts())[0].receipt).toBe(receipt);
    await page.getByRole('button', { name: 'إضافة', exact: true }).click();
    await page.waitForURL('**/operations/paths');
    expect(await drafts()).toEqual([]);
    expect(errors).toEqual([]);
});

test('teacher drafts omit credentials even when the form changes', async ({ page }) => {
    const name = `مسودة المحاضر ${Date.now()}`;
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto('/operations/teachers/create');
    await expect(page.locator('[data-authoring-draft]')).toHaveAttribute('data-draft-ready', 'true');
    await page.getByLabel(/الاسم بالعربية/).fill(name);
    await expect.poll(() => page.evaluate(() => {
        return Object.entries(localStorage).filter(([key]) => key.startsWith('rokn:course-authoring-draft:') && key.includes('/operations/teachers/create:'))
            .map(([, value]) => JSON.parse(value).values.name_ar);
    })).toContain(name);
    await page.getByRole('switch', { name: 'تعديل بيانات الدخول' }).check();
    await expect(page.getByLabel(/الاسم بالعربية/)).toHaveValue(name);
    await page.getByLabel('البريد الإلكتروني', { exact: true }).fill('private@example.test');
    await page.getByLabel('كلمة المرور الجديدة', { exact: true }).fill('Private-do-not-persist-123');
    await expect.poll(() => page.evaluate(() => {
        return Object.entries(localStorage).filter(([key]) => key.startsWith('rokn:course-authoring-draft:') && key.includes('/operations/teachers/create:'))
            .map(([, value]) => JSON.parse(value).values.name_ar);
    })).toContain(name);
    const publicDrafts = await page.evaluate(() => Object.entries(localStorage)
        .filter(([key]) => key.startsWith('rokn:course-authoring-draft:')).map(([, value]) => value).join(''));
    expect(publicDrafts).not.toContain('private@example.test');
    expect(publicDrafts).not.toContain('Private-do-not-persist');
    expect(errors).toEqual([]);
});

test('course studio shell keeps the existing unsent draft across refresh', async ({ page }) => {
    page.on('dialog', dialog => dialog.accept());
    const name = `كورس لم يحفظ ${Date.now()}`;
    await page.goto('/dashboard/courses/create');
    await page.getByLabel('اسم الكورس', { exact: true }).fill(name);
    await expect.poll(() => page.evaluate(() => Object.entries(localStorage)
        .filter(([key]) => key.startsWith('rokn:course-authoring-draft:') && key.includes('/dashboard/courses/create:'))
        .some(([, value]) => JSON.parse(value).values.name_ar?.trim().length > 0))).toBe(true);
    const receipt = await page.locator('[name=authoring_draft_receipt]').inputValue();
    await page.reload();
    await expect(page.getByLabel('اسم الكورس', { exact: true })).toHaveValue(name);
    await expect(page.locator('[name=authoring_draft_receipt]')).toHaveValue(receipt);
    await expect(page.locator('[name=authoring_request_id]')).toHaveValue(receipt);
});

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

test('instructor public profile saves without changing login credentials', async ({ page }, testInfo) => {
    const name = `مدرب المتصفح ${Date.now()}`;
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/operations/teachers/create');
    await page.getByLabel(/الاسم بالعربية/).fill(name);
    await page.getByLabel('المسمى الوظيفي', { exact: true }).fill('تصميم البراند');
    await page.locator('.fi-fo-file-upload').scrollIntoViewIfNeeded();
    await expect(page.locator('.filepond--root')).toBeVisible();
    await page.locator('.filepond--root input[type=file]').setInputFiles('public/images/logo.png');
    await expect(page.locator('[data-filepond-item-state="processing-complete"]')).toBeVisible();
    await page.getByRole('button', { name: 'إضافة', exact: true }).click();
    await page.waitForURL('**/operations/teachers');
    await page.getByRole('row').filter({ hasText: name }).getByRole('link', { name: 'تعديل', exact: true }).click();
    await page.getByLabel(/الاسم بالعربية/).fill(`${name} المعتمد`);
    await page.getByRole('button', { name: 'حفظ التغييرات', exact: true }).click();
    await expect(page.getByText('تم الحفظ', { exact: true })).toBeVisible();
    await page.reload();
    await expect(page.getByLabel(/الاسم بالعربية/)).toHaveValue(`${name} المعتمد`);
    await expect(page.getByLabel('البريد الإلكتروني', { exact: true })).not.toBeVisible();
    await page.locator('.fi-fo-file-upload').scrollIntoViewIfNeeded();
    await expect(page.locator('.filepond--root')).toBeVisible();
    const audit = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
    expect(audit.violations).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    await page.evaluate(() => scrollTo(0, 0));
    await page.screenshot({ path: testInfo.outputPath('teacher-edit-phone.png'), fullPage: true });
    expect(errors).toEqual([]);
});

test('home row is authored through the native schema on a phone', async ({ page }, testInfo) => {
    const name = `صف المتصفح ${Date.now()}`;
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/operations/classifications/create');
    await page.getByLabel(/الاسم بالعربية/).fill(name);
    await page.getByLabel(/الاسم بالإنجليزية/).fill('Content creation');
    await page.getByRole('spinbutton', { name: /الترتيب/ }).fill('2');
    await page.getByRole('button', { name: 'إضافة', exact: true }).click();
    await page.waitForURL('**/operations/classifications');
    await page.getByRole('row').filter({ hasText: name }).getByRole('link', { name: 'تعديل', exact: true }).click();
    await page.getByLabel(/الاسم بالعربية/).fill(`${name} المعتمد`);
    await page.getByRole('button', { name: 'حفظ التغييرات', exact: true }).click();
    await expect(page.getByText('تم الحفظ', { exact: true })).toBeVisible();
    await page.reload();
    await expect(page.getByLabel(/الاسم بالعربية/)).toHaveValue(`${name} المعتمد`);
    await page.getByRole('button', { name: 'الكورسات', exact: true }).scrollIntoViewIfNeeded();
    const audit = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
    expect(audit.violations).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    await page.evaluate(() => scrollTo(0, 0));
    await page.screenshot({ path: testInfo.outputPath('classification-edit-phone.png'), fullPage: true });
});

test('badge image is uploaded and preserved in the editor', async ({ page }, testInfo) => {
    const name = `شارة المتصفح ${Date.now()}`;
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/operations/levels/create');
    await page.getByLabel(/الاسم بالعربية/).fill(name);
    await page.getByLabel(/الاسم بالإنجليزية/).fill('Junior');
    await page.locator('.fi-fo-file-upload').scrollIntoViewIfNeeded();
    await expect(page.locator('.filepond--root')).toBeVisible();
    await page.locator('.filepond--root input[type=file]').setInputFiles('public/assets/app-artwork/v1/junior.png');
    await expect(page.locator('[data-filepond-item-state="processing-complete"]')).toBeVisible();
    await page.getByRole('button', { name: 'إضافة', exact: true }).click();
    await page.waitForURL('**/operations/levels');
    await page.getByRole('row').filter({ hasText: name }).getByRole('link', { name: 'تعديل', exact: true }).click();
    const preview = page.locator('main img').first();
    await expect(preview).toBeVisible();
    await expect.poll(() => preview.evaluate(image => image.naturalWidth)).toBeGreaterThan(0);
    await page.getByLabel(/الوصف بالعربية/).fill('الوصف المعتمد');
    await page.getByRole('button', { name: 'حفظ التغييرات', exact: true }).click();
    await expect(page.getByText('تم الحفظ', { exact: true })).toBeVisible();
    await page.reload();
    await expect(page.getByLabel(/الوصف بالعربية/)).toHaveValue('الوصف المعتمد');
    await page.locator('.fi-fo-file-upload').scrollIntoViewIfNeeded();
    await expect(page.locator('.filepond--root')).toBeVisible();
    const audit = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
    expect(audit.violations).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    await page.evaluate(() => scrollTo(0, 0));
    await page.screenshot({ path: testInfo.outputPath('badge-edit-phone.png'), fullPage: true });
    expect(errors).toEqual([]);
});
