import { test as setup } from '@playwright/test';
import { signIn } from './support/authentication.mjs';

setup('sign in with the actual dashboard password and MFA flow', async ({ page }) => {
    await signIn(page);
    await page.context().storageState({ path: 'playwright/.auth/dashboard.json' });
});
