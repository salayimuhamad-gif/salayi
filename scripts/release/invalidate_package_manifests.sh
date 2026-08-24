#!/usr/bin/env bash
# Invalidate the generated Composer/Laravel package-discovery manifests.
#
# THE defect this closes (final release rehearsal #36): Laravel writes
# bootstrap/cache/packages.php and bootstrap/cache/services.php from whatever
# vendor tree is present the first time it boots. The rehearsal boots the
# baseline on the CI dev stand-in vendor, whose nunomaduro/collision
# auto-discovers NunoMaduro\Collision\Adapters\Laravel\CollisionServiceProvider
# into both manifests. After the deployment replaces vendor with the
# production --no-dev tree, the very next artisan command — package:discover
# itself — boots THROUGH the stale manifests, tries to instantiate the
# Collision provider, and dies on a class the shipped vendor deliberately
# does not contain. The rebuild can never run because booting far enough to
# rebuild is exactly what the stale state prevents.
#
# So: whenever the dependency tree is REPLACED (deployment forward, rollback
# backward), these two generated files — and ONLY these two; the config,
# route, view and event caches are separate concerns with their own
# clear/rebuild steps — must be removed BEFORE the first artisan command
# under the new tree. Laravel 12 regenerates both on the next boot /
# package:discover from the vendor that is actually present.
#
# Usage: invalidate_package_manifests.sh <application-dir>
set -eu

APP_DIR="${1:?usage: invalidate_package_manifests.sh <application-dir>}"
[ -d "$APP_DIR" ] || { echo "FAIL: no such application directory: $APP_DIR" >&2; exit 1; }

# The single source of truth for WHICH generated discovery files a vendor
# swap invalidates. The release-tooling regressions pin the rehearsals and
# both runbooks to this exact list.
MANIFESTS="bootstrap/cache/packages.php bootstrap/cache/services.php"

for rel in $MANIFESTS; do
    if [ -e "$APP_DIR/$rel" ]; then
        rm -f "$APP_DIR/$rel"
        echo "invalidated: $rel"
    else
        echo "already absent: $rel"
    fi
    [ ! -e "$APP_DIR/$rel" ] || { echo "FAIL: could not remove $rel" >&2; exit 1; }
done
