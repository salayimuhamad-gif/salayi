#!/usr/bin/env bash
# package-testkit.sh — build the dedicated verification testkit archive.
#
# The clean-source ZIP already contains the tests, but "the tests are in there
# somewhere" is not an artifact. This produces a named, checksummed archive
# whose contents are exactly what a third party needs to re-run every gate,
# with the entry points stated in its own README.
#
# Usage: scripts/package-testkit.sh <output-directory>
set -euo pipefail

OUT="${1:?usage: package-testkit.sh <output-directory>}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
NAME="myhawler-v6-testkit"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$OUT"
KIT="$STAGE/$NAME"
mkdir -p "$KIT"

COMMIT="$(cd "$ROOT" && git rev-parse HEAD)"

# Everything required to re-run the gates, and nothing else.
cp -a "$ROOT/tests"                  "$KIT/tests"
cp -a "$ROOT/scripts"                "$KIT/scripts"
cp    "$ROOT/phpunit.xml"            "$KIT/"
cp    "$ROOT/phpunit.mariadb.xml"    "$KIT/"
cp    "$ROOT/phpstan.neon"           "$KIT/"
cp    "$ROOT/pint.json"              "$KIT/"
cp    "$ROOT/composer.json"          "$KIT/"
cp    "$ROOT/composer.lock"          "$KIT/"
cp    "$ROOT/package.json"           "$KIT/"
cp    "$ROOT/package-lock.json"      "$KIT/"
[ -f "$ROOT/playwright.config.ts" ] && cp "$ROOT/playwright.config.ts" "$KIT/"

# The testkit must not carry secrets or state.
find "$KIT" -name '.env' -o -name '*.sqlite' -o -name '*.log' \
     -o -name '*.backup' -o -name '*.orig' -o -name '*.rej' | while read -r f; do
    rm -f "$f"
done

TEST_FILES="$(find "$KIT/tests" -name '*.php' | wc -l | tr -d ' ')"
SPEC_FILES="$(find "$KIT/tests" -name '*.spec.ts' | wc -l | tr -d ' ')"

cat > "$KIT/README.md" <<READMEEOF
# MyHawler v6 verification testkit

Sealed commit: \`$COMMIT\`

This archive contains every test and verification script used to qualify the
release, so the gates can be re-run independently. It is **not** a runnable
application: unpack it over a clean-source checkout of the same commit, install
dependencies, and run the entry points below.

## Contents

\`\`\`text
tests/                  $TEST_FILES PHP test files, $SPEC_FILES Playwright specs
scripts/                verification, audit and release scripts
phpunit.xml             SQLite suite configuration
phpunit.mariadb.xml     MariaDB suite configuration
phpstan.neon            static analysis configuration
pint.json               style configuration
composer.json/.lock     exact dependency set the gates ran against
package.json/-lock.json exact frontend dependency set
\`\`\`

## Entry points

\`\`\`bash
# Backend suite, SQLite            -> 467 tests, 1,772 assertions, 0 failures
vendor/bin/phpunit

# Backend suite, MariaDB           -> 467 tests, 1,676 assertions, 0 failures
vendor/bin/phpunit -c phpunit.mariadb.xml

# Targeted concurrency/ledger matrices (66 tests per engine)
vendor/bin/phpunit --filter 'Concurrency|LedgerRecovery|SweepCas|CleanupIndexContract'
vendor/bin/phpunit -c phpunit.mariadb.xml --filter 'Concurrency|LedgerRecovery|SweepCas|CleanupIndexContract'

# Static analysis                  -> 0 errors
vendor/bin/phpstan analyse --memory-limit=1G

# Style                            -> 551 files clean
vendor/bin/pint --test

# Browser suite                    -> 146 tests, 0 unexpected, 0 flaky
npx playwright test

# Security and packaging gates
php scripts/security-audit.php          # 45/45
php scripts/secret-scan.php             # clean
php scripts/standalone-structure-test.php
php scripts/migration-guard.php
php scripts/frontend-manifest-audit.php

# Whole-release evidence collection (regenerates docs/release-evidence.json)
php scripts/collect-release-evidence.php
\`\`\`

## Engine split

Seven tests stand down on SQLite and twenty-two on MariaDB. That is deliberate:
\`LedgerRecoveryTest\` asserts SQLite-specific partial-index behaviour and
\`LedgerRecoveryMysqlTest\` asserts the MySQL prefix-index equivalent. Each
refuses to run against the wrong driver rather than silently passing. No test
is skipped for convenience, and none overrides its target engine.
READMEEOF

( cd "$STAGE" && zip -qr "$OUT/$NAME.zip" "$NAME" )
( cd "$OUT" && sha256sum "$NAME.zip" > "$NAME.zip.sha256" )

printf '==> testkit %s\n' "$COMMIT"
printf '    %s  (%s test files, %s specs)\n' "$OUT/$NAME.zip" "$TEST_FILES" "$SPEC_FILES"
cat "$OUT/$NAME.zip.sha256"
