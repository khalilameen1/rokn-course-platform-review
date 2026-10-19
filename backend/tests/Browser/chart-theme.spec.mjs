import { test, expect } from '@playwright/test';

// axe cannot inspect text painted into canvas. Inspect the labels Chart.js paints,
// including after Filament changes theme without remounting the widget.
async function chartLabels(page, type) {
    return page.locator(`[data-chart-type="${type}"]`).evaluate(element => {
        const component = window.Alpine.$data(element);
        const chart = typeof component.getChart === 'function' ? component.getChart() : null;
        if (!chart) return [];
        let background = element;
        while (background && ['transparent', 'rgba(0, 0, 0, 0)'].includes(getComputedStyle(background).backgroundColor)) {
            background = background.parentElement;
        }
        const pixel = document.createElement('canvas').getContext('2d', { willReadFrequently: true });
        const luminance = color => {
            pixel.clearRect(0, 0, 1, 1);
            pixel.fillStyle = color;
            pixel.fillRect(0, 0, 1, 1);
            const channels = [...pixel.getImageData(0, 0, 1, 1).data].slice(0, 3).map(value => {
                const normalized = value / 255;
                return normalized <= 0.04045 ? normalized / 12.92 : ((normalized + 0.055) / 1.055) ** 2.4;
            });
            return channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
        };
        const surface = getComputedStyle(background).backgroundColor;
        return Object.entries(chart.scales).flatMap(([axis, scale]) => scale.getLabelItems().map(item => {
            const ink = luminance(item.options.color);
            const paper = luminance(surface);
            return { axis, color: item.options.color, contrast: (Math.max(ink, paper) + 0.05) / (Math.min(ink, paper) + 0.05) };
        }));
    });
}

test('both financial charts keep readable labels through native light and dark switching', async ({ page }, testInfo) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/operations/financial-overview');
    for (const [type, tab] of [['line', null], ['bar', 'عملات الكورسات']]) {
        if (tab) await page.getByRole('tab', { name: tab, exact: true }).click();
        const component = page.locator(`[data-chart-type="${type}"]`);
        await expect(component.locator('canvas')).toBeVisible();
        for (const [theme, label] of [['light', 'تفعيل الوضع النهاري'], ['dark', 'تفعيل الوضع الليلي']]) {
            await page.getByRole('button', { name: 'قائمة المستخدم' }).click();
            await page.getByRole('menuitemradio', { name: label }).click();
            await expect(page.locator('html')).toHaveClass(theme === 'dark' ? /dark/ : /^(?!.*\bdark\b)/);
            for (const width of [390, 1440]) {
                await page.setViewportSize({ width, height: 900 });
                await expect.poll(async () => (await chartLabels(page, type)).length).toBeGreaterThan(0);
                await expect.poll(async () => Math.min(...(await chartLabels(page, type)).map(item => item.contrast))).toBeGreaterThanOrEqual(4.5);
                await page.evaluate(() => scrollTo(0, 0));
                await expect(page.locator('main h1')).toBeInViewport();
                expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
                await page.screenshot({ path: testInfo.outputPath(`${type}-${theme}-${width}.png`), fullPage: true });
                await testInfo.attach(`${type}-${theme}-${width}-label-contrast`, { body: JSON.stringify(await chartLabels(page, type), null, 2), contentType: 'application/json' });
            }
        }
    }
    expect(errors).toEqual([]);
});
