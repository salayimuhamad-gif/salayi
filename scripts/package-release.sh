#!/usr/bin/env bash
#
# Build the release archives (File one §13), reproducibly, from committed HEAD.
#
# The previous version of this script produced the audited delivery's defects
# rather than preventing them, in three ways worth naming because each is a
# class of failure rather than a typo:
#
#   1. `VERSION=$(php -r '...') 2>/dev/null || echo "4.0"` silently degraded to
#      the literal string "4.0" when PHP was absent. That is why the shipped
#      archives are named Mulkihawler_4.0_* while config/mulkihawler.php says
#      4.0.0-step8. A missing toolchain produced a plausible filename instead
#      of an error.
#
#   2. `git archive HEAD` honours export-ignore. tests/, .github/ and the repo
#      dotfiles were excluded from the Clean Source ZIP by a .gitattributes
#      rule while SHA256SUMS.txt was generated from the working tree — 125
#      entries that verify against files no customer received, and no failure
#      at packaging time because the export did precisely what it was told.
#
#   3. Nothing checked whether the working tree matched HEAD, so an archive
#      could be built from a commit while the checksums described uncommitted
#      edits.
#
# Every one of those is now a hard stop. The script fails loudly rather than
# shipping something that looks finished.
#
# Usage: bash scripts/package-release.sh [output-directory]

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$ROOT/dist}"

# ABSOLUTE, always. `zip_reproducibly` builds each archive from inside the
# staging tree (`cd "$base"`), so a RELATIVE output path resolves against that
# temporary directory rather than the caller's — `zip` then fails with
# "Could not create output file" two minutes into a run that had already passed
# every gate. The default is absolute, which is why the defect only appeared
# the first time CI passed the documented relative form (`dist-a`). A relative
# path now means what it says: relative to the directory the script was invoked
# from, resolved once, here.
case "$OUT" in
    /*) ;;
    *)  OUT="$(pwd)/$OUT" ;;
esac
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

# Reproducibility depends on a fixed timezone and a fixed timestamp. Both
# derive from the commit, so two builds of the same HEAD are byte-identical.
export TZ=UTC
export LC_ALL=C

die() { printf '\nFATAL: %s\n' "$*" >&2; exit 1; }
step() { printf '\n--> %s\n' "$*"; }

# ---------------------------------------------------------------- toolchain
# Checked before anything else, and never with a fallback. A packaging run on a
# host missing its tools must fail, not improvise.
step "toolchain"
for tool in git php zip sha256sum composer; do
    command -v "$tool" >/dev/null 2>&1 \
        || die "$tool is not installed. Packaging requires it; there is no fallback."
done
printf '    php %s\n' "$(php -r 'echo PHP_VERSION;')"

# -------------------------------------------------------------- clean tree
step "repository state"
git -C "$ROOT" rev-parse --git-dir >/dev/null 2>&1 \
    || die "not a git repository — archives are built from committed history only."

if [ -n "$(git -C "$ROOT" status --porcelain)" ]; then
    printf '\n%s\n' "$(git -C "$ROOT" status --short)" >&2
    die "working tree is dirty. Commit or stash first: the archive is built from HEAD and would not contain the changes above."
fi

COMMIT="$(git -C "$ROOT" rev-parse HEAD)"
SOURCE_DATE_EPOCH="$(git -C "$ROOT" show -s --format=%ct HEAD)"
export SOURCE_DATE_EPOCH
ZIP_TIMESTAMP="$(date -u -d "@$SOURCE_DATE_EPOCH" +'%Y%m%d%H%M.%S')"
printf '    HEAD %s (%s)\n' "${COMMIT:0:12}" "$(date -u -d "@$SOURCE_DATE_EPOCH" +'%Y-%m-%d %H:%M:%SZ')"

# ----------------------------------------------------------------- version
# No fallback. The version is whatever the application says it is.
#
# config/mulkihawler.php calls env(), which does not exist outside a booted
# Laravel application — so `php -r 'require config/mulkihawler.php'` fatals.
# The previous script hid that behind `2>/dev/null || echo "4.0"`, which means
# the version lookup failed on every machine, every time, and the literal
# string "4.0" was never a naming decision at all. A one-line stub for env()
# lets the file be read as the data it is.
VERSION="$(php -r '
    if (! function_exists("env")) {
        function env(string $key, mixed $default = null): mixed { return $default; }
    }
    $config = require "'"$ROOT"'/config/mulkihawler.php";
    echo $config["version"] ?? "";
')"
[ -n "$VERSION" ] || die "config/mulkihawler.php does not define a version."
printf '    version %s\n' "$VERSION"

# --------------------------------------------------------------- preflight
step "preflight gates"
[ -f "$ROOT/composer.lock" ] \
    || die "composer.lock is missing. A release without a lock file is not reproducible and must not be packaged."
[ -d "$ROOT/vendor" ] || die "vendor/ is missing. Run: composer install"
[ -f "$ROOT/public/build/manifest.json" ] \
    || die "no Vite manifest. Run: npm ci && npx vite build"

php "$ROOT/scripts/lang-parity.php" --strict > /dev/null
php "$ROOT/scripts/lang-usage.php" > /dev/null
php "$ROOT/scripts/secret-scan.php" > /dev/null
php "$ROOT/scripts/migration-guard.php" > /dev/null
php "$ROOT/scripts/security-audit.php" > /dev/null
php "$ROOT/scripts/frontend-guard.php" > /dev/null
printf '    standalone gates passed\n'

# ---------------------------------------------------- full configured gates
#
# The audited release ran PHPUnit and the standalone scripts and nothing else,
# so `composer lint`, `composer stan` and every frontend gate were configured
# but never enforced at packaging time. A gate nobody runs is documentation.
step "configured quality gates"

composer validate --strict > /dev/null || die "composer validate --strict failed."
printf '    composer validate --strict\n'

(cd "$ROOT" && composer lint > /dev/null) || die "composer lint (Pint) failed. Run: composer fix"
printf '    composer lint\n'

# Static analysis is a BLOCKING gate. Carrying known debt requires an explicit,
# recorded product-owner decision — never a silent override — and even then the
# release decision records the debt rather than hiding it.
if (cd "$ROOT" && composer stan > /dev/null 2>&1); then
    printf '    composer stan\n'
elif [ "${RELEASE_ALLOW_STATIC_ANALYSIS_DEBT:-0}" = "1" ]; then
    printf '    composer stan FAILED — building REVIEW artefacts under an explicit override.\n'
    printf '    The release decision will record NOT READY; these archives are not a release.\n'
else
    die "composer stan failed. Repair the findings, or obtain product-owner approval for a reviewed baseline. Set RELEASE_ALLOW_STATIC_ANALYSIS_DEBT=1 only to build review artefacts, which are NOT a production release."
fi

step "frontend gates"
(cd "$ROOT" && npm run typecheck > /dev/null) || die "TypeScript check failed."
(cd "$ROOT" && npm run lint > /dev/null) || die "ESLint failed."
(cd "$ROOT" && npm run build > /dev/null) || die "production frontend build failed."
printf '    typecheck, eslint, vite build\n'

# ------------------------------------------------- documentation consistency
#
# Packaging must not proceed while the shipped documentation contradicts the
# tree. Both generators are idempotent and fail when their output is stale.
step "documentation consistency"
php "$ROOT/scripts/generate-model-annotations.php" --check > /dev/null \
    || die "model annotations are stale. Run: php scripts/generate-model-annotations.php"
php "$ROOT/scripts/generate-release-docs.php" --check > /dev/null \
    || die "release documentation is stale. Run: php scripts/generate-release-docs.php"
php "$ROOT/scripts/doc-consistency.php" > /dev/null \
    || die "documentation contradicts itself or the evidence. Run: php scripts/doc-consistency.php"

# The recipient runs the generator inside the ARCHIVE, so it has to be
# reproducible there. A release once shipped whose own --check failed on
# extraction because a count was measured in a state packaging removes.
php "$ROOT/scripts/doc-portability.php" > /dev/null \
    || die "the documentation does not regenerate identically from a packaged tree. Run: php scripts/doc-portability.php"
printf '    generated documents match the tree\n'

# PHPUnit is a gate, not a courtesy. If the suite cannot run, nothing is built.
[ -x "$ROOT/vendor/bin/phpunit" ] \
    || die "vendor/bin/phpunit not found — the test suite has never run against this tree."
(cd "$ROOT" && vendor/bin/phpunit) > /dev/null \
    || die "test suite failed. Fix it; do not package around it."
printf '    test suite passed\n'

mkdir -p "$OUT"

zip_reproducibly() {
    # Deterministic: fixed mtimes, sorted entry order, no extra attributes.
    #
    # The `rm -f` is not tidiness. `zip` APPENDS to an existing archive, so a
    # second packaging run into a directory that already holds an archive
    # merges the two — the new build's files land alongside the previous
    # build's, which still verify individually and are invisible in any
    # per-file check. That is how 78 stale hashed assets from an earlier build
    # survived inside a delivered Clean Source ZIP while the staging tree that
    # produced it was clean. Always start from no archive.
    local archive="$1" base="$2"
    rm -f "$archive"
    (
        cd "$base"
        find . -exec touch -t "$ZIP_TIMESTAMP" {} +
        find . \( -type f -o -type l \) | LC_ALL=C sort | sed 's|^\./||' \
            | zip -qX -9 "$archive" -@
    )

    # The archive is verified against the tree it was built from, by count.
    # A per-file checksum check cannot see a surplus file; only a count can.
    local staged zipped
    staged="$(cd "$base" && find . -type f | wc -l | tr -d ' ')"
    zipped="$(unzip -l "$archive" | tail -1 | awk '{print $2}')"

    [ "$staged" = "$zipped" ] \
        || die "archive holds $zipped files but the staging tree has $staged. Refusing to ship an archive that does not match its source."

    printf '    %s (%s files, verified against staging tree)\n' \
        "$(basename "$archive")" "$zipped"
}

# ------------------------------------------------------------ clean source
step "clean source"
SRC="$STAGE/src/mulkihawler"
mkdir -p "$SRC"

# The export must be complete, so any export-ignore rule is a packaging bug.
if git -C "$ROOT" check-attr --all -- . 2>/dev/null | grep -q 'export-ignore'; then
    die "an export-ignore attribute is set. The Clean Source ZIP must be a complete checkout; remove it from .gitattributes."
fi

git -C "$ROOT" archive --format=tar "$COMMIT" | tar -x -C "$SRC"

# Compiled assets are gitignored and required, so they are copied explicitly.
cp -r "$ROOT/public/build" "$SRC/public/"

# §1.1 lists what "buildable and testable" means; each item is verified.
for required in \
    tests \
    tests/Feature \
    .github/workflows/ci.yml \
    .gitignore \
    .gitattributes \
    .editorconfig \
    composer.json \
    composer.lock \
    phpunit.xml \
    package.json \
    package-lock.json \
    public/build/manifest.json
do
    [ -e "$SRC/$required" ] || die "Clean Source is missing '$required'. §1.1 requires it."
done

TEST_COUNT="$(find "$SRC/tests" -name '*Test.php' | wc -l | tr -d ' ')"
[ "$TEST_COUNT" -gt 0 ] || die "Clean Source contains no test files."
printf '    %s test file(s) included\n' "$TEST_COUNT"

# A writable storage skeleton with no runtime residue.
(
    cd "$SRC"
    find storage -type f ! -name '.gitignore' -delete 2>/dev/null || true
    for d in storage/framework/cache/data storage/framework/sessions \
             storage/framework/views storage/app/public storage/logs \
             bootstrap/cache; do
        mkdir -p "$d"
        printf '*\n!.gitignore\n' > "$d/.gitignore"
    done
)

for forbidden in .env vendor node_modules storage/installed.lock; do
    [ ! -e "$SRC/$forbidden" ] || die "'$forbidden' is present in the source package."
done

# The internal manifest is generated AFTER the contents are final, from the
# staged tree that is about to be zipped — never from the working directory.
# That ordering is the entire fix for the 220 stale entries.
step "internal manifest"
(
    cd "$SRC"
    rm -f SHA256SUMS.txt
    find . -type f ! -name 'SHA256SUMS.txt' -printf '%P\n' | LC_ALL=C sort \
        | xargs -d '\n' sha256sum > SHA256SUMS.txt
    sha256sum -c --quiet SHA256SUMS.txt
) || die "the manifest just written does not verify against its own tree."
printf '    %s file(s) hashed and verified\n' \
    "$(wc -l < "$SRC/SHA256SUMS.txt" | tr -d ' ')"

zip_reproducibly "$OUT/Mulkihawler_${VERSION}_Clean_Source.zip" "$STAGE/src"

# -------------------------------------------------------------- deployment
step "hostinger deployment"
DEP="$STAGE/deploy"
mkdir -p "$DEP/application" "$DEP/public_html"
cp -a "$SRC/." "$DEP/application/"

(
    cd "$DEP"
    # `mv public/*` silently leaves dotfiles behind, and public/ carries
    # .htaccess.
    cp -a application/public/. public_html/
    rm -rf application/public
)

# §1.4: the deployment archive must run after upload. That means vendor/,
# production-only, resolved from the committed lock file.
step "production dependencies"
(
    cd "$DEP/application"
    composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --optimize-autoloader \
        --classmap-authoritative > /dev/null
)
[ -f "$DEP/application/vendor/autoload.php" ] \
    || die "vendor/autoload.php was not produced; the deployment archive would not boot."
printf '    vendor/ installed (%s packages)\n' \
    "$(php -r '$l = json_decode(file_get_contents("'"$DEP"'/application/composer.lock"), true); echo count($l["packages"] ?? []);')"

# Development tooling has no business on a production host. Removed HERE,
# visibly, rather than by an invisible export-ignore.
#
# The list is explicit and the assertion below is what actually enforces it.
# playwright.config.ts shipped in a previous build precisely because a new dev
# file was added and nothing failed when the list did not grow with it — a
# deny-list nobody checks is a deny-list that quietly stops working.
rm -rf "$DEP/application/tests" \
       "$DEP/application/phpunit.xml" \
       "$DEP/application/phpunit.mariadb.xml" \
       "$DEP/application/eslint.config.js" \
       "$DEP/application/vite.config.ts" \
       "$DEP/application/postcss.config.js" \
       "$DEP/application/tailwind.config.ts" \
       "$DEP/application/tsconfig.json" \
       "$DEP/application/package.json" \
       "$DEP/application/package-lock.json" \
       "$DEP/application/phpstan.neon" \
       "$DEP/application/pint.json" \
       "$DEP/application/.github" \
       "$DEP/application/resources/js" \
       "$DEP/application/resources/css" \
       "$DEP/application/playwright.config.ts" \
       "$DEP/application/playwright.config.js" \
       "$DEP/application/playwright-report" \
       "$DEP/application/test-results" \
       "$DEP/application/coverage" \
       "$DEP/application/.playwright" \
       "$DEP/application/storage/browser-acceptance" \
       "$DEP/application/storage/browser-archive" \
       "$DEP/application/scripts" \
       "$DEP/application/docs" \
       "$DEP/application/CODING_STANDARDS.md" \
       "$DEP/application/SHA256SUMS.txt" \
       "$DEP/application/.editorconfig" \
       "$DEP/application/.gitattributes" \
       "$DEP/application/.gitignore"

# The deny-list above is a promise; this is the check. Anything matching a
# development shape that survives into the deployment tree fails the build
# rather than shipping.
step "deployment hygiene"
# vendor/ is pruned before the scan. Third-party packages ship their own
# pint.json and phpunit.xml.dist inside their distributions; those are part of
# the dependency, not of this project's development tooling, and flagging them
# would make the check cry wolf until somebody switched it off.
STRAYS="$(cd "$DEP/application" && find . -path './vendor' -prune -o \
    \( -name '*.spec.ts' -o -name '*.test.ts' -o -name 'playwright.config.*' \
       -o -name '*.backup' -o -name '*.orig' -o -name '*.rej' -o -name 'phpunit*.xml' -o -name 'phpunit.xml*' -o -name 'phpstan*' -o -name 'pint.json' \
       -o -name '.env' -o -name '*.dist' -o -path './tests/*' \
       -o -path './scripts/*' -o -path './docs/*' -o -path './.git/*' \
       -o -path './node_modules/*' \) -print | head -20)"

if [ -n "$STRAYS" ]; then
    printf '%s\n' "$STRAYS"
    die "development-only files survived into the deployment tree."
fi

# Development Composer packages must not be present either. The install above
# is --no-dev, so this asserts the install actually behaved.
for devpkg in phpunit mockery nunomaduro/collision laravel/pint phpstan; do
    if [ -e "$DEP/application/vendor/$devpkg" ]; then
        die "development dependency present in deployment vendor/: $devpkg"
    fi
done

printf '    no dev files, no dev dependencies\n'

cp "$ROOT/deploy/.htaccess" "$DEP/public_html/.htaccess"
cp "$ROOT/deploy/.user.ini" "$DEP/public_html/.user.ini"
cp "$ROOT/deploy/DEPLOYMENT_README.txt" "$DEP/DEPLOYMENT_README.txt"

# The deployment tree's own manifest, regenerated after the trimming above.
# vendor/ is excluded by path: it is reproducible from composer.lock, and
# hashing 8000 files would make the manifest unreadable without adding trust.
(
    cd "$DEP/application"
    rm -f SHA256SUMS.txt
    find . -type f ! -name 'SHA256SUMS.txt' ! -path './vendor/*' -printf '%P\n' \
        | LC_ALL=C sort | xargs -d '\n' sha256sum > SHA256SUMS.txt
    sha256sum -c --quiet SHA256SUMS.txt
) || die "the deployment manifest does not verify against its own tree."

# ------------------------------------------- complete deployment manifest
#
# `application/SHA256SUMS.txt` covers the APPLICATION SOURCE only — roughly 527
# files out of nearly 6,900 in the finished archive. Vendor, the built frontend
# and the deployment README were all outside it, so "manifest verified" said
# almost nothing about what a customer actually receives. This manifest covers
# every regular file in the deployment tree except itself.
# The source-only manifest is renamed so it cannot be mistaken for the whole.
if [ -f "$DEP/application/SHA256SUMS.txt" ]; then
    mv "$DEP/application/SHA256SUMS.txt" "$DEP/application/APPLICATION_SOURCE_SHA256SUMS.txt"
fi

step "complete deployment manifest"
(
    cd "$DEP"
    find . -type f ! -name 'DEPLOYMENT_SHA256SUMS.txt' -printf '%P\n' \
        | LC_ALL=C sort \
        | xargs -d '\n' sha256sum > DEPLOYMENT_SHA256SUMS.txt
)
DEPLOY_MANIFEST_COUNT="$(wc -l < "$DEP/DEPLOYMENT_SHA256SUMS.txt")"
printf '    DEPLOYMENT_SHA256SUMS.txt covers %s file(s)\n' "$DEPLOY_MANIFEST_COUNT"

zip_reproducibly "$OUT/Mulkihawler_${VERSION}_Production_Deployment.zip" "$DEP"

# --------------------------------------------------------------- checksums
step "release checksums"
(
    cd "$OUT"
    sha256sum "Mulkihawler_${VERSION}_Clean_Source.zip" \
              "Mulkihawler_${VERSION}_Production_Deployment.zip" > SHA256SUMS.txt
    sha256sum -c --quiet SHA256SUMS.txt
)

# ------------------------------------------------- archive content audit
#
# The manifest proves the archive matches the staging tree. It does NOT prove
# the staging tree was right. These are the properties a customer-facing
# archive must have regardless of how it was assembled.
step "archive content audit"
CLEAN_ZIP="$OUT/Mulkihawler_${VERSION}_Clean_Source.zip"
DEPLOY_ZIP="$OUT/Mulkihawler_${VERSION}_Production_Deployment.zip"

for z in "$CLEAN_ZIP" "$DEPLOY_ZIP"; do
    unzip -t "$z" > /dev/null || die "archive integrity check failed: $z"
done

# The listing is captured ONCE per archive rather than piped per pattern.
# `unzip -l | grep -q` looks harmless but grep exits at the first match, unzip
# takes SIGPIPE, and under `set -o pipefail` the whole pipeline reports failure —
# so a SUCCESSFUL match read as a failed check. Reading a file has no such trap,
# and it is far quicker over an archive with thousands of entries.
listing_of() {
    local zip="$1"
    local cache="$STAGE/.listing-$(basename "$zip").txt"

    if [ ! -f "$cache" ]; then
        unzip -l "$zip" > "$cache"
    fi

    printf '%s' "$cache"
}

audit_absent() {
    local zip="$1" pattern="$2" label="$3"
    local listing
    listing="$(listing_of "$zip")"

    if grep -qE "$pattern" "$listing"; then
        grep -E "$pattern" "$listing" | head -5 >&2
        die "$(basename "$zip") contains $label, which must never ship."
    fi
}

audit_present() {
    local zip="$1" pattern="$2" label="$3"
    local listing
    listing="$(listing_of "$zip")"

    grep -qF "$pattern" "$listing" \
        || die "$(basename "$zip") is missing $label."
}

# Packaging internals must never be delivered. `.bundle-list` shipped inside the
# Release Bundle because it was written into the directory being archived; the
# fix moved it, and these patterns make sure a future one cannot repeat it.
PACKAGING_INTERNALS='(^|/)\.?(bundle-list|file-list|archive-list)(\.txt)?$|manifest\.tmp$|\.tmp$'

for z in "$CLEAN_ZIP" "$DEPLOY_ZIP" ; do
    audit_absent "$z" "$PACKAGING_INTERNALS"               "a packaging internal file"
    audit_absent "$z" '(^|/)\.env$'                       "a .env file"
    audit_absent "$z" '/node_modules/'                     "node_modules"
    audit_absent "$z" '\.log$'                             "runtime logs"
    audit_absent "$z" '\.sqlite($|-)'                      "a database file"
    audit_absent "$z" 'cleanup-journal'                    "a runtime cleanup journal"
    audit_absent "$z" '\.phpunit\.(cache|result\.cache)'   "PHPUnit cache"
    audit_absent "$z" 'installed\.lock'                    "an installer lock"
    audit_absent "$z" 'storage/framework/views/[0-9a-f]{32}' "compiled views"
    audit_absent "$z" 'phpunit-mysql\.xml'                 "a verification-only config"
done

# The clean source carries no vendor; the deployment archive must.
audit_absent "$CLEAN_ZIP" '(^|/)vendor/' "vendor/"
audit_present "$DEPLOY_ZIP" 'application/vendor/autoload.php' \
    "application/vendor/autoload.php; it would not boot"

# Placeholders that keep the runtime directories present after extraction.
for placeholder in \
    storage/framework/cache/data/.gitignore \
    storage/framework/sessions/.gitignore \
    storage/framework/views/.gitignore \
    storage/logs/.gitignore; do
    audit_present "$CLEAN_ZIP" "$placeholder" "$placeholder"
    audit_present "$DEPLOY_ZIP" "application/$placeholder" "application/$placeholder"
done

audit_present "$CLEAN_ZIP" 'composer.lock' 'composer.lock'
printf '    content audit passed for both archives\n'

# -------------------------------------------------- extraction smoke tests
#
# An archive that verifies its own manifest can still be unusable. These run
# the checks that only mean something after extraction.
step "extraction smoke tests"
SMOKE="$(mktemp -d)"
trap 'rm -rf "$STAGE" "$SMOKE"' EXIT

(
    cd "$SMOKE" && unzip -q "$CLEAN_ZIP" && cd mulkihawler
    sha256sum -c SHA256SUMS.txt --quiet || exit 1
    find app tests scripts -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null || exit 1
    php tests/Standalone/run.php > /dev/null || exit 1
    php scripts/secret-scan.php > /dev/null || exit 1
) || die "clean-source extraction smoke test failed."
printf '    clean source: manifest, syntax, standalone suite, secret scan\n'

(
    cd "$SMOKE" && mkdir deploy-smoke && cd deploy-smoke && unzip -q "$DEPLOY_ZIP"
    [ -f application/vendor/autoload.php ] || exit 1
    [ -f public_html/index.php ] || exit 1
    grep -q "application" public_html/index.php || exit 1
    [ -f application/public/build/manifest.json ] || [ -f public_html/build/manifest.json ] || exit 1
    # No development-only tooling in a production package.
    [ ! -d application/tests ] || exit 1
    [ ! -d application/node_modules ] || exit 1
) || die "deployment extraction smoke test failed."
printf '    deployment: autoload, split front controller, vite manifest, no dev files\n'

# ------------------------------------------------------- delivery bundle
#
# Assembled HERE rather than by hand: the audited delivery was a wrapper ZIP
# containing the two archives and silently omitting SHA256SUMS.txt, so a
# recipient had nothing to verify the pair against.
step "delivery bundle"

# The two run-derived reports come from the EXTERNAL evidence directory, not
# from docs/. generate-release-docs.php writes them to
# <evidence-dir>/reports/ deliberately: they carry the gate table and the tree
# identity, so writing them inside the source made the source change whenever
# evidence was collected. This script was still reading the pre-externalisation
# in-tree paths and failed with a bare `cp: cannot stat`. The directory is
# resolved through EvidencePath itself — the same single source of truth every
# other consumer uses — so MYHAWLER_EVIDENCE_DIR relocates the reports here
# exactly as it does for the generator. (EvidencePath's other channel,
# `--evidence-dir=`, is read from a script's own argv; this script takes only
# an output directory, so the environment variable is the override it honours.)
REPORTS="$(php -r '
    require $argv[1]."/scripts/support/EvidencePath.php";
    echo Mulkihawler\Tooling\EvidencePath::directory($argv[1]);
' "$ROOT")/reports"

for report in FINAL_RELEASE_VERIFICATION.md RELEASE_DECISION.md; do
    [ -f "$REPORTS/$report" ] \
        || die "$report is missing from $REPORTS. Run: php scripts/collect-release-evidence.php"
done

cp "$REPORTS/FINAL_RELEASE_VERIFICATION.md" "$OUT/FINAL_RELEASE_REPORT.md"
cp "$REPORTS/RELEASE_DECISION.md" "$OUT/RELEASE_DECISION.md"

BUNDLE="$OUT/Mulkihawler_${VERSION}_Release_Bundle.zip"
rm -f "$BUNDLE"
(
    cd "$OUT"

    # The file list lives OUTSIDE the output directory. Written here, it was
    # created before `find` ran, so it listed itself and shipped inside the
    # bundle — a build temp file delivered to customers. An independent audit
    # of the finished archive caught it; the packaging script's own checks did
    # not, because they were looking for secrets rather than for its own litter.
    list="$STAGE/bundle-list.txt"

    find . -maxdepth 1 -type f ! -name "$(basename "$BUNDLE")" -printf '%P\n' \
        | LC_ALL=C sort > "$list"

    # Fixed mtimes keep two builds of one commit byte-identical.
    while read -r f; do touch -t "$ZIP_TIMESTAMP" "$f"; done < "$list"

    zip -q -X -@ "$(basename "$BUNDLE")" < "$list"
)
unzip -t "$BUNDLE" > /dev/null || die "delivery bundle failed its integrity check."
printf '    %s\n' "$(basename "$BUNDLE")"

printf '\n==> packaged %s from %s\n' "$VERSION" "${COMMIT:0:12}"
ls -lh "$OUT"/Mulkihawler_*.zip | awk '{print "    " $9 "  " $5}'
cat "$OUT/SHA256SUMS.txt"
printf '\nbundle: %s\n' "$(cd "$OUT" && sha256sum "Mulkihawler_${VERSION}_Release_Bundle.zip")"
