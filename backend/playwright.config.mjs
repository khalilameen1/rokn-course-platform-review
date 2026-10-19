import { defineConfig } from '@playwright/test';
import { phpArguments } from './tests/Browser/support/authentication.mjs';

const php = ['php', ...phpArguments()].join(' ');

export default defineConfig({
    testDir: './tests/Browser',
    fullyParallel: false,
    workers: 1,
    timeout: 60000,
    // The disposable Windows PHP server handles Livewire requests serially.
    expect: { timeout: 15000 },
    retries: 0,
    reporter: [['list'], ['html', { open: 'never' }]],
    use: {
        baseURL: 'http://127.0.0.1:8793',
        channel: process.env.CI ? undefined : 'chrome',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    webServer: {
        command: `${php} scripts/dashboard-browser.php --prepare && ${php} -S 127.0.0.1:8793 -t public scripts/dashboard-browser.php`,
        url: 'http://127.0.0.1:8793/login',
        reuseExistingServer: false,
        timeout: 120000,
    },
    projects: [
        { name: 'setup', testMatch: /auth\.setup\.mjs/ },
        { name: 'dashboard', testIgnore: /auth\.setup\.mjs/, dependencies: ['setup'], use: { storageState: 'playwright/.auth/dashboard.json' } },
    ],
});
