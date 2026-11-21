import { defineConfig, devices } from '@playwright/test';

/**
 * Browser tests against a real `php artisan serve`, on their own SQLite
 * database (database/e2e.sqlite) filled by E2eSeeder. Real CSRF, real file
 * cache and sessions, the test payment gateway: what the unit tests cannot see.
 *
 *   npm run build && npx playwright test
 */
const port = 8020;

// E2E_DB=mysql runs on MySQL (database store_e2e), the engine of production.
const mysql = process.env.E2E_DB === 'mysql';

const e2eEnv = {
    APP_ENV: 'local',
    APP_DEBUG: 'true',
    APP_URL: `http://127.0.0.1:${port}`,
    DB_CONNECTION: mysql ? 'mysql' : 'sqlite',
    DB_DATABASE: mysql ? 'store_e2e' : 'database/e2e.sqlite',
    DB_HOST: '127.0.0.1',
    DB_USERNAME: process.env.E2E_DB_USERNAME ?? 'root',
    DB_PASSWORD: process.env.E2E_DB_PASSWORD ?? '',
    DB_URL: '',
    CACHE_STORE: 'file',
    SESSION_DRIVER: 'file',
    QUEUE_CONNECTION: 'sync',
    MAIL_MAILER: 'log',
    PAYMENT_GATEWAY: 'fake',
    // The shop's numbers, which the WhatsApp links need before Settings has its own.
    STORE_PHONE: '+972 50-000-0000',
    STORE_WHATSAPP: '+972500000000',
    STORE_REQUIRE_STAFF_2FA: 'false',
    INERTIA_SSR_ENABLED: 'false',
    TURNSTILE_SITE_KEY: '',
    TURNSTILE_SECRET_KEY: '',
};

export default defineConfig({
    testDir: './e2e',
    fullyParallel: false,
    workers: 1,
    retries: process.env.CI ? 1 : 0,
    reporter: process.env.CI ? 'github' : 'list',
    timeout: 60_000,
    use: {
        baseURL: `http://127.0.0.1:${port}`,
        locale: 'ar',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        {
            name: 'desktop',
            use: {
                ...devices['Desktop Chrome'],
                viewport: { width: 1366, height: 860 },
            },
            testIgnore: /admin-mobile\.spec\.ts|zz-/,
        },
        {
            name: 'mobile',
            use: { ...devices['Pixel 7'] },
            testMatch: /(storefront|admin-mobile)\.spec\.ts/,
        },
    ],
    webServer: {
        // The database is prepared first, with the same settings as the server.
        command: `node e2e/prepare.mjs && php artisan serve --host=127.0.0.1 --port=${port}`,
        url: `http://127.0.0.1:${port}/up`,
        env: e2eEnv,
        reuseExistingServer: false,
        timeout: 60_000,
    },
});
