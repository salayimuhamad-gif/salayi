#!/usr/bin/env node
/*
 * Compile and run the pure TypeScript suite.
 *
 * No test framework: vite and node are both already present. Adding vitest or
 * jest would mean another install that can fail in a restricted network, and
 * these tests exist precisely for environments where the heavier toolchain is
 * unavailable.
 *
 * Usage: node tests/js/run.mjs
 */
import { execFileSync } from 'node:child_process';
import { existsSync, rmSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const out = resolve(root, '.tsbuild');

/*
 * Dependencies are a prerequisite, and saying so beats a cryptic resolver
 * error. The suite needs typescript, vite and @types/node — all declared
 * devDependencies, but only present after an install.
 */
if (!existsSync(resolve(root, 'node_modules/typescript'))
    || !existsSync(resolve(root, 'node_modules/@types/node'))) {
    console.error('Dependencies are missing. Run `npm ci` first.');
    process.exit(1);
}

/*
 * Type-check FIRST, with the suite's own tsconfig.
 *
 * Vite transpiles without checking types, so a suite that only ran through
 * vite could contain type errors and still pass — the tests would be executed
 * but not verified. Both steps are required: tsc proves the suite agrees with
 * the source's types, node proves the behaviour.
 */
try {
    execFileSync('npx', ['tsc', '--noEmit', '-p', resolve(here, 'tsconfig.json')], {
        cwd: root,
        stdio: 'inherit',
    });
    console.log('typecheck: PASS');
} catch {
    console.error('\nThe TypeScript suite does not type-check.');
    process.exit(1);
}

try {
    execFileSync('npx', ['vite', 'build', '--config', resolve(here, 'vite.config.mjs'), '--logLevel', 'warn'], {
        cwd: root,
        stdio: 'inherit',
    });
} catch {
    console.error('\nBundling the TypeScript suite failed.');
    process.exit(1);
}

let status = 0;

try {
    // Every suite, each failing the run on its own exit code.
    for (const suite of ['geojson.mjs', 'geometry.mjs', 'wizard.mjs', 'trend.mjs']) {
        execFileSync('node', [resolve(out, suite)], { cwd: root, stdio: 'inherit' });
    }
} catch {
    status = 1;
} finally {
    rmSync(out, { recursive: true, force: true });
}

process.exit(status);
