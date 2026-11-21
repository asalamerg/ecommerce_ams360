// Runs before the test server starts (see playwright.config.ts): a fresh
// database filled with the known E2E store, and an empty cache.
import { execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';

if (process.env.DB_CONNECTION === 'mysql') {
    execFileSync(
        'php',
        [
            '-r',
            `$p = new PDO('mysql:host=${process.env.DB_HOST}', '${process.env.DB_USERNAME}', getenv('DB_PASSWORD') ?: ''); $p->exec('CREATE DATABASE IF NOT EXISTS ${process.env.DB_DATABASE} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');`,
        ],
        { stdio: 'inherit' },
    );
} else {
    writeFileSync('database/e2e.sqlite', '');
}

for (const args of [
    [
        'artisan',
        'migrate:fresh',
        '--seed',
        '--seeder=E2eSeeder',
        '--force',
        '--no-interaction',
    ],
    ['artisan', 'cache:clear'],
    // A fresh checkout (CI) has no public/storage link. /storage/* then falls
    // through to the private disk's signed-URL route and every image is a 403.
    ['artisan', 'storage:link'],
]) {
    execFileSync('php', args, { stdio: 'inherit' });
}
