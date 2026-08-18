import { execFileSync } from 'node:child_process';

/*
 * Clears Laravel's cache before the suite runs.
 *
 * The only thing this exists for is the login rate limiter: the admin spec
 * submits a deliberately wrong password, and across repeated runs those
 * attempts accumulate until `throttle:login` starts answering 429. Every
 * sign-in then fails and the failure is indistinguishable from broken
 * authentication.
 *
 * Skipped silently when the app cannot be reached from the runner — pointing the
 * suite at an already-deployed archive is a supported mode, and the deployment
 * under test is not necessarily on this filesystem.
 */
export default async function globalSetup(): Promise<void> {
    await assertServerCanOverlapRequests(process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8100');

    try {
        execFileSync('php', ['artisan', 'cache:clear'], { stdio: 'ignore' });
    } catch {
        // Nothing to clear here; the target is remote or artisan is unavailable.
    }
}

/**
 * Harness self-check: the server under test must be able to overlap requests.
 *
 * PHP's built-in server handles one request at a time unless
 * `PHP_CLI_SERVER_WORKERS` forks more. The Playwright config sets it when IT
 * starts the server — but a run against an externally started server
 * (`PLAYWRIGHT_NO_SERVER=1`) inherits whatever that server was launched with,
 * and a single-worker server starves the account-first journey: the browser's
 * poll competes with a cold context's asset downloads, and the confirmation
 * never appears. That surfaced as an intermittent Arabic failure — Arabic being
 * simply the point in the sequence where the backlog peaked, not a property of
 * the locale.
 *
 * A silent starve reads exactly like a product race, which is the worst
 * possible failure mode for a suite whose job is to tell those apart. So the
 * suite refuses to run against a server that cannot overlap requests.
 */
export async function assertServerCanOverlapRequests(baseURL: string): Promise<void> {
    const slowPath = `${baseURL.replace(/\/$/, '')}/register`;

    const started = Date.now();

    // Six concurrent requests. On a single-worker server these serialise; with
    // workers they overlap and finish in roughly the time of the slowest one.
    const timings = await Promise.all(
        Array.from({ length: 6 }, async () => {
            const t0 = Date.now();
            await fetch(slowPath, { headers: { Accept: 'text/html' } });

            return Date.now() - t0;
        }),
    );

    const wall = Date.now() - started;
    const slowest = Math.max(...timings);
    const total = timings.reduce((a, b) => a + b, 0);

    // Serialised: wall clock approaches the SUM. Overlapping: wall clock stays
    // near the slowest single request.
    if (wall > slowest * 3 && wall > total * 0.7) {
        throw new Error(
            'The test server appears to serve one request at a time '
            + `(6 requests: wall ${wall}ms, slowest ${slowest}ms, sum ${total}ms). `
            + 'Start it with PHP_CLI_SERVER_WORKERS=10, or let Playwright start it. '
            + 'A single-worker server starves polling and produces failures that '
            + 'look like product races.',
        );
    }
}
