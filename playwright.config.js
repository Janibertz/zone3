// Browser-Smoke-Tests (tests/e2e, seit 15.11.2026). Jede Kernseite einmal am
// iPhone und am Desktop: lädt sie ohne Serverfehler, ohne JS-Fehler in der
// Konsole, und steht das Wichtigste da? Vorbereiten mit tests/e2e/setup.sh.
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: 'tests/e2e',
    // Ein Webserver mit einem Prozess: nacheinander, nicht parallel.
    workers: 1,
    fullyParallel: false,
    retries: process.env.CI ? 1 : 0,
    timeout: 30_000,
    reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
    use: {
        baseURL: 'http://127.0.0.1:8123',
        locale: 'de-DE',
        timezoneId: 'Europe/Berlin',
        screenshot: 'on',
        trace: 'retain-on-failure',
    },
    projects: [
        { name: 'anmelden', testMatch: /auth\.setup\.js/ },
        {
            name: 'iphone',
            dependencies: ['anmelden'],
            use: { ...devices['iPhone 14'], browserName: 'chromium', storageState: 'test-results/.auth/testdev.json' },
        },
        {
            name: 'desktop',
            dependencies: ['anmelden'],
            use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 }, storageState: 'test-results/.auth/testdev.json' },
        },
    ],
    webServer: {
        command: 'bash tests/e2e/serve.sh',
        url: 'http://127.0.0.1:8123/login',
        reuseExistingServer: !process.env.CI,
        timeout: 60_000,
    },
});
