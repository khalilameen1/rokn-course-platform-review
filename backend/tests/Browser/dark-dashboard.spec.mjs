import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

// Reuse Filament's actual theme switch, not injected CSS or a screenshot-only shell.
const workspaces = [
    ['daily', '/operations'],
    ['content', '/operations/content-workspace'],
    ['students', '/operations/students'],
    ['orders', '/operations/orders'],
    ['projects', '/operations/project-submissions'],
    ['settings', '/operations/app-settings'],
    ['financial', '/operations/financial-overview'],
    ['commercial', '/operations/commercial-report'],
    ['runtime', '/operations/product-operations'],
    ['health', '/operations/system-status'],
];

for (const [name, path] of workspaces) {
    test(`dark ${name} workspace remains readable and responsive`, async ({ page }, testInfo) => {
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(path);
        await page.getByRole('button', { name: 'قائمة المستخدم' }).click();
        await page.getByRole('menuitemradio', { name: 'تفعيل الوضع الليلي' }).click();
        await page.reload();
        await expect(page.locator('html')).toHaveClass(/dark/);
        if (name === 'daily') {
            await expect(page.getByText('مبيعات اليوم المؤكدة', { exact: true })).toBeVisible();
        }
        for (const width of [390, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            await expect(page.locator('main h1')).toBeVisible();
            await page.evaluate(() => document.fonts.ready);
            if (width >= 1024) {
                // Responsive Alpine x-show fades are not the final text color.
                // Wait for the actual sidebar labels/buttons, not a fixed delay
                // or an axe exclusion that would hide a real contrast defect.
                await expect.poll(() => page.locator('.fi-sidebar-item-label:visible, .fi-sidebar-group-btn:visible')
                    .evaluateAll(elements => elements.length > 0 && elements.every(element => getComputedStyle(element).opacity === '1'))).toBe(true);
            }
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
            expect((await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze()).violations).toEqual([]);
            await page.evaluate(() => scrollTo(0, 0));
            await page.screenshot({ path: testInfo.outputPath(`${name}-dark-${width}.png`), fullPage: true });
        }
        expect(errors).toEqual([]);
    });
}
