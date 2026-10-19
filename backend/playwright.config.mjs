import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './tests/Browser',
    fullyParallel: false,
    workers: 1,
    timeout: 60000,
    retries: 0,
    reporter: [['list'], ['html', { open: 'never' }]],
    use: {
        baseURL: 'http://127.0.0.1:8793',
        channel: 'chrome',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    webServer: {
        command: 'php -c php.ini scripts/dashboard-browser.php --prepare && php -c php.ini -S 127.0.0.1:8793 -t public scripts/dashboard-browser.php',
        url: 'http://127.0.0.1:8793/login',
        reuseExistingServer: false,
        timeout: 120000,
    },
});
