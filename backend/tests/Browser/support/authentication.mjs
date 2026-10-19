import { existsSync } from 'node:fs';
import { execFileSync } from 'node:child_process';

export const phpArguments = () => existsSync('php.ini') ? ['-c', 'php.ini'] : [];

export async function signIn(page) {
    await page.goto('/operations');
    await page.locator('#email').fill('admin@dashboard.example.test');
    await page.locator('#password').fill('Local-browser-fixture-2026');
    await page.locator('#loginForm button[type=submit]').click();
    await page.waitForURL('**/dashboard/mfa/challenge');
    const otp = execFileSync('php', [...phpArguments(), 'scripts/dashboard-browser.php', '--otp'], { encoding: 'utf8' }).trim();
    await page.locator('#code').fill(otp);
    await page.locator('button[type=submit]').click();
    await page.waitForURL(url => !url.pathname.includes('/mfa/'));
}
