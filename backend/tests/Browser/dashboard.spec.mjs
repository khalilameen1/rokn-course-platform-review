import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';

test('real dashboard session, RTL search, mobile reflow, dark mode and accessibility', async ({ page }, testInfo) => {
    const errors = [];
    page.on('pageerror', error => { errors.push(error.message); console.error(error.message); });
    page.on('console', message => { if (message.type() === 'error') console.error(message.text()); });
    await page.goto('/operations');
    await page.locator('#email').fill('admin@dashboard.example.test');
    await page.locator('#password').fill('Local-browser-fixture-2026');
    await page.locator('#loginForm button[type=submit]').click();
    await page.waitForURL('**/dashboard/mfa/challenge');
    const otp = execFileSync('php', ['-c', 'php.ini', 'scripts/dashboard-browser.php', '--otp'], { encoding: 'utf8' }).trim();
    await page.locator('#code').fill(otp);
    await page.locator('button[type=submit]').click();
    await page.goto('/operations/students');
    await expect(page.getByRole('heading', { name: 'الطلبة', exact: true })).toBeVisible();
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    const search = page.locator('.fi-ta-search-field input');
    await search.fill('notfound@example.test');
    await expect(page.getByText('لا يوجد طلبة', { exact: true })).toBeVisible();
    await search.fill('client@dashboard.example.test');
    await expect(page.getByText('client@dashboard.example.test', { exact: true })).toBeVisible();
    for (const width of [360, 390, 768, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await page.evaluate(() => document.fonts.ready);
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
