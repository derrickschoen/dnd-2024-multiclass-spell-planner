import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './e2e',
    fullyParallel: false,
    workers: 1,
    timeout: 45_000,
    expect: {
        timeout: 10_000,
    },
    reporter: 'list',
    use: {
        // Per-worktree ddev sites override this so parallel branches can run the
        // browser suite concurrently instead of queueing behind one shared site.
        baseURL: process.env.E2E_BASE_URL ?? 'https://dnd-spell-planner.ddev.site',
        headless: true,
        ignoreHTTPSErrors: true,
        actionTimeout: 10_000,
        navigationTimeout: 20_000,
        trace: 'retain-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
