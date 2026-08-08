import { defineConfig } from '@playwright/test';

/*
 * Browser acceptance for the public surface.
 *
 * WHY THE BROWSER EXECUTABLE IS CONFIGURABLE.
 *
 * `npx playwright install` fetches browsers from cdn.playwright.dev. That host
 * is unreachable from some build networks — including the one this suite was
 * authored on, which returns HTTP 403 — so a config that can only use
 * Playwright's own download is a config that cannot run there, and a gate that
 * cannot run is a gate nobody enforces.
 *
 * `PLAYWRIGHT_CHROMIUM_PATH` therefore points at any Chromium build. Leave it
 * unset on a normal machine and Playwright uses its managed browser as usual.
 *
 * WHY THE SERVER IS OPTIONAL.
 *
 * Two things must be tested and they are not the same thing: the working tree,
 * and the exact immutable Production ZIP after extraction. Setting
 * `PLAYWRIGHT_NO_SERVER=1` with `PLAYWRIGHT_BASE_URL` points the identical
 * specs at an already-running deployment, so the archive is verified by the
 * same assertions as the source rather than by a second, weaker suite.
 *
 * WHY workers = 1 AND PHP_CLI_SERVER_WORKERS > 1.
 *
 * PHP's built-in server handles one request at a time by default. A browser
 * loading the homepage asks for the document and then its JS and CSS, and the
 * asset requests were being closed mid-flight — so Vue never mounted, every
 * page rendered as an empty Inertia shell, and the failures read like product
 * defects instead of a starved test server. `PHP_CLI_SERVER_WORKERS` forks
 * enough workers to serve a page and its bundle together; Playwright still
 * runs one worker, because the server is shared state.
 */
/*
 * ONE canonical origin. The server port used to be hardcoded in the webServer
 * command while APP_URL was written independently by the CI runner, so
 * `TelegramReturnUrl` built return buttons pointing at a port with no server on
 * it — and the cold-browser return scenarios navigated nowhere. The port is now
 * derived from the base URL, so the two cannot drift.
 */
const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8100';
const serverPort = new URL(baseURL).port || '8100';
const executablePath = process.env.PLAYWRIGHT_CHROMIUM_PATH;
const evidenceDir = process.env.PLAYWRIGHT_EVIDENCE_DIR ?? 'storage/browser-acceptance';

/*
 * The five viewports the release brief requires, smallest first. Each runs the
 * whole suite: a layout defect at 360 is invisible at 1440, which is the entire
 * reason the list has five entries and not one.
 */
const viewports = [
    { name: 'mobile-360x800', width: 360, height: 800, isMobile: true },
    { name: 'mobile-390x844', width: 390, height: 844, isMobile: true },
    { name: 'tablet-768x1024', width: 768, height: 1024, isMobile: false },
    { name: 'laptop-1366x768', width: 1366, height: 768, isMobile: false },
    { name: 'desktop-1440x900', width: 1440, height: 900, isMobile: false },
];

/*
 * The login route is rate-limited (`throttle:login`), and it must be — but the
 * admin and MFA specs deliberately submit a wrong password, so a suite that ran
 * twice in quick succession tripped the limiter and every subsequent sign-in
 * returned 429. The symptom looked exactly like broken authentication.
 *
 * globalSetup clears the cache store the limiter counts in, so each run starts
 * from a known state. It resets a counter; it does not weaken the limit, and the
 * limiter itself is still exercised in production.
 */
export default defineConfig({
    /*
     * NO VCS METADATA IN THE REPORTS.
     *
     * Playwright captures git information on CI by default and writes it into
     * `config.metadata.gitCommit`, INCLUDING the full commit message body. The
     * release runner points Playwright's JSON output straight at the evidence
     * directory, so that message became sealed release evidence.
     *
     * Final release #5 failed there: a commit message that explained a
     * regression quoted an environment assignment as prose, the reporter copied
     * the whole message into all five project reports, and the clean-directory
     * secret scanner correctly refused the delivery. The scanner was right —
     * the evidence really did contain secret-shaped text.
     *
     * Git discovery finds a repository even though the verified source archive
     * is gitless: the disposable browser workspace lives under $WORK, which the
     * workflow places inside the GitHub checkout, so the walk upward reaches
     * that checkout's .git.
     *
     * The release contract identifies the source by TREE_MANIFEST.sha256, never
     * by commit prose, so none of this metadata is evidence of anything. It is
     * switched off at the source here, and normalize_browser_report.py strips
     * it from the written reports as well — the reports must be safe whatever a
     * future Playwright version decides to capture by default.
     */
    captureGitInfo: { commit: false, diff: false },

    globalSetup: './tests/Browser/support/global-setup.ts',
    testDir: './tests/Browser',
    timeout: 90_000,
    expect: { timeout: 15_000 },
    fullyParallel: false,
    workers: 1,
    forbidOnly: true,
    retries: 0,
    reporter: [
        ['list'],
        ['json', { outputFile: `${evidenceDir}/BROWSER_ACCEPTANCE.json` }],
    ],
    outputDir: `${evidenceDir}/artifacts`,

    use: {
        baseURL,
        screenshot: 'only-on-failure',
        video: 'off',
        trace: 'retain-on-failure',
        launchOptions: {
            ...(executablePath === undefined ? {} : { executablePath }),
            // --no-sandbox is required to run as root in a container. It is a
            // test-harness concession and touches nothing that ships.
            args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu'],
        },
    },

    projects: viewports.map((viewport) => ({
        name: viewport.name,
        use: {
            viewport: { width: viewport.width, height: viewport.height },
            isMobile: viewport.isMobile,
            hasTouch: viewport.isMobile,
        },
    })),

    webServer:
        process.env.PLAYWRIGHT_NO_SERVER === '1'
            ? undefined
            : {
                  command: `php artisan serve --host=127.0.0.1 --port=${serverPort}`,
                  url: baseURL,
                  reuseExistingServer: true,
                  timeout: 120_000,
                  env: { PHP_CLI_SERVER_WORKERS: '10' },
              },
});
