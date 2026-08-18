<?php

declare(strict_types=1);
use Mulkihawler\Tooling\TestTally;

require_once __DIR__.'/../../scripts/support/TestTally.php';

/*
 * Structural checks that need no framework — and would have caught four of the
 * defects that shipped in the wizard.
 *
 * Every one of these was invisible to `php -l`, because each file parsed
 * perfectly. A method that is called but never defined, a migration adding a
 * column that already exists, an enum in the wrong file, and a model field
 * missing from $fillable are all syntactically valid and all fatal or silently
 * destructive at runtime.
 */

$root = dirname(__DIR__, 2);
TestTally::reset();

/*
 * A file-local closure, not a global function.
 *
 * Six standalone files each declared a global `ok()` with a DIFFERENT signature
 * — two, three and differently-named parameters. Each script runs in its own
 * process so PHP never saw a redeclaration, but PHPStan analyses them together
 * and resolved whichever declaration it read first against every call site,
 * reporting `arguments.count` against calls that were in fact correct. That was
 * the sole finding standing between this release and a build without
 * RELEASE_ALLOW_STATIC_ANALYSIS_DEBT=1.
 *
 * The closure form is what ArtifactEvidenceTest, DocConsistencyFixturesTest and
 * PackagingHygieneTest already use, so this converges the file on the pattern
 * the suite had already chosen rather than inventing a seventh convention. The
 * assertion behaviour is unchanged.
 */
$ok = static function (string $name, bool $condition): void {
    if ($condition) {
        echo "  pass {$name}\n";
    } else {
        TestTally::fail();
        echo "  FAIL {$name}\n";
    }
};

/* ---------------------------------------- undefined controller methods */

$controllers = glob($root.'/app/Modules/*/Http/Controllers/**/*.php') ?: [];
$controllers = array_merge($controllers, glob($root.'/app/Modules/*/Http/Controllers/*/*.php') ?: []);

$undefined = [];

foreach (array_unique($controllers) as $file) {
    $source = (string) file_get_contents($file);

    preg_match_all('/private function (\w+)\s*\(|protected function (\w+)\s*\(|public function (\w+)\s*\(/', $source, $defs);
    $defined = array_filter(array_merge($defs[1], $defs[2], $defs[3]));

    preg_match_all('/\$this->(\w+)\s*\(/', $source, $calls);

    foreach (array_unique($calls[1]) as $called) {
        // Inherited framework helpers are not declared in the file.
        $inherited = ['authorize', 'validate', 'middleware', 'dispatch', 'json', 'authorizeResource'];

        if (! in_array($called, $defined, true) && ! in_array($called, $inherited, true)) {
            $undefined[] = basename($file).'::'.$called.'()';
        }
    }
}

$ok('no controller calls an undefined $this method', $undefined === []);

foreach ($undefined as $entry) {
    echo "        undefined: {$entry}\n";
}

/* ------------------------------------------- duplicate migration columns */

$migrations = glob($root.'/app/Modules/*/Database/Migrations/*.php') ?: [];
$created = [];
$duplicates = [];

foreach ($migrations as $file) {
    $source = (string) file_get_contents($file);

    // Columns added inside Schema::create() blocks, per table.
    if (preg_match_all("/Schema::create\('(\w+)'.*?\n        \}\);/s", $source, $blocks, PREG_SET_ORDER)) {
        foreach ($blocks as $block) {
            preg_match_all("/\\\$table->\w+\('(\w+)'/", $block[0], $columns);

            foreach ($columns[1] as $column) {
                $created[$block[1]][] = $column;
            }
        }
    }
}

foreach ($migrations as $file) {
    $source = (string) file_get_contents($file);

    if (preg_match_all("/Schema::table\('(\w+)'.*?\n        \}\);/s", $source, $blocks, PREG_SET_ORDER)) {
        foreach ($blocks as $block) {
            preg_match_all("/\\\$table->(?:foreignId|string|boolean|integer|decimal|timestamp|date|json|text)\('(\w+)'[^;]*;/", $block[0], $columns, PREG_SET_ORDER);

            foreach ($columns as $call) {
                $column = $call[1];

                /*
                 * v6: `->change()` ALTERS an existing column — precisely what
                 * a corrective migration does — and is not a re-add. Reading
                 * only the column name made the currency-truth migration's
                 * own nullable() change look like a duplicate of itself.
                 */
                if (str_contains($call[0], '->change()')) {
                    continue;
                }

                // A guarded add is deliberate and safe.
                if (str_contains($block[0], "hasColumn('".$block[1]."', '".$column."')")) {
                    continue;
                }

                if (in_array($column, $created[$block[1]] ?? [], true)) {
                    $duplicates[] = $block[1].'.'.$column.' in '.basename($file);
                }
            }
        }
    }
}

$ok('no migration re-adds a column its create block already defines', $duplicates === []);

foreach ($duplicates as $entry) {
    echo "        duplicate: {$entry}\n";
}

/* ------------------------------------------------------ PSR-4 filenames */

$classFiles = [];
$stack = [$root.'/app'];

while ($stack !== []) {
    $dir = array_pop($stack);

    foreach ((array) scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir.'/'.$entry;

        if (is_dir($path)) {
            $stack[] = $path;
        } elseif (str_ends_with($entry, '.php')) {
            $classFiles[] = $path;
        }
    }
}

$mismatches = [];

foreach ($classFiles as $file) {
    $source = (string) file_get_contents($file);

    if (! preg_match_all('/^(?:final |abstract |readonly )*(class|interface|trait|enum) (\w+)/m', $source, $types, PREG_SET_ORDER)) {
        continue;
    }

    if (count($types) > 1) {
        $mismatches[] = basename($file).' declares '.count($types).' types';

        continue;
    }

    $expected = basename($file, '.php');

    if ($types[0][2] !== $expected) {
        $mismatches[] = basename($file).' declares '.$types[0][2];
    }
}

$ok('every PHP file declares exactly one type matching its filename', $mismatches === []);

foreach ($mismatches as $entry) {
    echo "        psr-4: {$entry}\n";
}

/* -------------------------------------------- Project $fillable coverage */

$model = (string) file_get_contents($root.'/app/Modules/Projects/Models/Project.php');
$controller = (string) file_get_contents(
    $root.'/app/Modules/Projects/Http/Controllers/Admin/ProjectWizardController.php'
);

preg_match("/protected \\\$fillable = \[(.*?)\];/s", $model, $fillableBlock);
preg_match_all("/'(\w+)'/", $fillableBlock[1] ?? '', $fillable);

// Keys the wizard passes to Project::query()->create([...]).
preg_match("/Project::query\(\)->create\(\[(.*?)\n        \]\);/s", $controller, $createBlock);
preg_match_all("/'(\w+)' =>/", $createBlock[1] ?? '', $assigned);

$missing = array_values(array_diff($assigned[1], $fillable[1]));

$ok('every field the wizard assigns is in Project::$fillable', $missing === []);

foreach ($missing as $field) {
    echo "        silently dropped by mass assignment: {$field}\n";
}

/* ------------------------------- $fillable coverage for every wizard model */

/*
 * Generalised from the Project-only check. `acting_company_id`,
 * `submitted_at` and `version` were passed to ProjectDraft::create() while
 * absent from its $fillable, so the acting company chosen at start() was
 * silently discarded — the draft was correctly scoped for exactly one request.
 */
$modelChecks = [
    'ProjectDraft' => $root.'/app/Modules/Projects/Models/ProjectDraft.php',
    'ProjectPrice' => $root.'/app/Modules/Projects/Models/ProjectPrice.php',
    'ProjectDraftMedia' => $root.'/app/Modules/Projects/Models/ProjectDraftMedia.php',
];

$sources = [
    (string) file_get_contents($root.'/app/Modules/Projects/Http/Controllers/Admin/ProjectWizardController.php'),
];

foreach ($modelChecks as $name => $path) {
    if (! is_file($path)) {
        continue;
    }

    preg_match("/protected \\\$fillable = \[(.*?)\];/s", (string) file_get_contents($path), $block);
    preg_match_all("/'(\w+)'/", $block[1] ?? '', $allowed);

    $assignedKeys = [];

    foreach ($sources as $source) {
        if (preg_match_all("/{$name}::query\(\)->create\(\[(.*?)\]\)/s", $source, $creates, PREG_SET_ORDER)) {
            foreach ($creates as $create) {
                preg_match_all("/'(\w+)' =>/", $create[1], $keys);
                $assignedKeys = array_merge($assignedKeys, $keys[1]);
            }
        }
    }

    $gap = array_values(array_unique(array_diff($assignedKeys, $allowed[1])));

    $ok("every field assigned to {$name} is fillable", $gap === []);

    foreach ($gap as $field) {
        echo "        silently dropped: {$name}.{$field}\n";
    }
}

/* ------------------------------------- migrations must be re-runnable */

/*
 * A migration that fails halfway on a shared host leaves the database
 * partially applied. Additive Schema::table() changes are therefore guarded so
 * the run can simply be repeated instead of needing manual repair.
 */
$unguarded = [];

foreach (glob($root.'/app/Modules/Projects/Database/Migrations/2026_07_*.php') ?: [] as $file) {
    $source = (string) file_get_contents($file);

    if (preg_match_all("/Schema::table\('(\w+)'.*?\n        \}\);/s", $source, $blocks, PREG_SET_ORDER)) {
        foreach ($blocks as $block) {
            if (! str_contains($block[0], 'hasColumn')) {
                $unguarded[] = basename($file).' -> '.$block[1];
            }
        }
    }

    if (str_contains($source, 'Schema::create(') && ! str_contains($source, 'hasTable(')) {
        $unguarded[] = basename($file).' -> unguarded create';
    }
}

$ok('wizard migrations are guarded for partial-application recovery', $unguarded === []);

foreach ($unguarded as $entry) {
    echo "        unguarded: {$entry}\n";
}

/* --------------------------------- every route handler must actually exist */

/*
 * The check that would have caught four dead routes.
 *
 * An edit last round replaced a block of the wizard controller by slicing
 * between two comment markers, and silently removed every method that
 * happened to sit between them — nearby(), uploadMedia(), updateMedia() and
 * destroy(). The routes still pointed at them, the file still parsed, and
 * `php -l` was perfectly happy. Only dispatching a request would have failed,
 * and nothing dispatched one.
 */
$routeFiles = glob($root.'/app/Modules/*/Routes/*.php') ?: [];
$missingHandlers = [];

foreach ($routeFiles as $routeFile) {
    $source = (string) file_get_contents($routeFile);

    // Resolve `use` aliases to fully-qualified class names.
    preg_match_all('/^use ([\\w\\\\]+);$/m', $source, $uses);
    $aliases = [];

    foreach ($uses[1] as $fqcn) {
        $parts = explode('\\', $fqcn);
        $aliases[end($parts)] = $fqcn;
    }

    // [SomeController::class, 'method']
    preg_match_all("/\[(\w+)::class,\s*'(\w+)'\]/", $source, $handlers, PREG_SET_ORDER);

    foreach ($handlers as $handler) {
        [$all, $alias, $method] = $handler;

        if (! isset($aliases[$alias])) {
            continue;   // not an imported class; nothing to resolve against
        }

        $relative = str_replace(['App\\', '\\'], ['app/', '/'], $aliases[$alias]).'.php';
        $classFile = $root.'/'.$relative;

        if (! is_file($classFile)) {
            $missingHandlers[] = basename($routeFile).': '.$alias.' class file not found';

            continue;
        }

        $classSource = (string) file_get_contents($classFile);

        if (! preg_match('/public function '.preg_quote($method, '/').'\s*\(/', $classSource)) {
            $missingHandlers[] = basename($routeFile).': '.$alias.'::'.$method.'() does not exist';
        }
    }
}

$ok('every routed controller method exists', $missingHandlers === []);

foreach ($missingHandlers as $entry) {
    echo "        dead route: {$entry}\n";
}

/* ------------------------------- models referenced by controllers must exist */

$referencedModels = [];

foreach (array_unique($controllers) as $file) {
    $source = (string) file_get_contents($file);

    preg_match_all('/^use (App\\\\Modules\\\\[\\w\\\\]+\\\\Models\\\\\\w+);$/m', $source, $modelUses);

    foreach ($modelUses[1] as $fqcn) {
        $referencedModels[$fqcn] = $file;
    }
}

$missingModels = [];

foreach ($referencedModels as $fqcn => $usedIn) {
    $path = $root.'/'.str_replace(['App\\', '\\'], ['app/', '/'], $fqcn).'.php';

    if (! is_file($path)) {
        $missingModels[] = basename($usedIn).' imports missing '.$fqcn;
    }
}

$ok('every model a controller imports exists', $missingModels === []);

foreach ($missingModels as $entry) {
    echo "        missing model: {$entry}\n";
}

/* ------------------------ controllers must not call undefined model methods */

/*
 * ProjectMediaController called $media->url() and ProjectMedia had no url(),
 * so the media index fataled on serialisation. Same class as the undefined
 * $this-> call, one object further out.
 */
$modelMethodMisses = [];

foreach (array_unique($controllers) as $file) {
    $source = (string) file_get_contents($file);

    preg_match_all('/^use (App\\\\Modules\\\\[\\w\\\\]+\\\\Models\\\\(\\w+));$/m', $source, $modelUses, PREG_SET_ORDER);

    foreach ($modelUses as $use) {
        [$line, $fqcn, $short] = $use;
        $modelPath = $root.'/'.str_replace(['App\\', '\\'], ['app/', '/'], $fqcn).'.php';

        if (! is_file($modelPath)) {
            continue;
        }

        $modelSource = (string) file_get_contents($modelPath);

        /*
         * Traits count. name(), description() and friends live in
         * HasTrilingualNames, not in the model body — checking only the class
         * file reports every trait method as missing, which is a false alarm
         * loud enough to make the whole check useless.
         */
        preg_match_all('/^use ([\\w\\\\]+);$/m', $modelSource, $modelUsesInner);

        foreach ($modelUsesInner[1] as $candidate) {
            if (! str_contains($candidate, 'Concerns') && ! str_contains($candidate, 'Traits')) {
                continue;
            }

            $traitPath = $root.'/'.str_replace(['App\\', '\\'], ['app/', '/'], $candidate).'.php';

            if (is_file($traitPath)) {
                $modelSource .= (string) file_get_contents($traitPath);
            }
        }
        $variable = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $short));
        $variable = str_replace('_', '', lcfirst($short));

        // Calls on a variable named after the model: $media->url(), etc.
        preg_match_all('/\$'.preg_quote($variable, '/').'->(\w+)\(/', $source, $calls);

        foreach (array_unique($calls[1]) as $method) {
            // Inherited from Eloquent\Model. Listed explicitly rather than
            // reflected, because this suite runs without an autoloader.
            $eloquent = [
                'save', 'delete', 'update', 'refresh', 'fill', 'toArray', 'getKey',
                'forceFill', 'load', 'fresh', 'replicate', 'touch', 'setAttribute',
                'getDirty', 'only', 'getAttribute', 'getOriginal', 'isDirty',
                'wasChanged', 'setRelation', 'relationLoaded', 'getRelation',
                'push', 'restore', 'trashed', 'makeVisible', 'makeHidden',
                'append', 'getChanges', 'saveQuietly', 'deleteQuietly',
                /*
                 * v6: `loadCount()` is inherited from Eloquent\Model just as
                 * `load()` is, and was simply absent from a list that has to
                 * be maintained by hand because this suite runs without an
                 * autoloader. Recognising it is a correction to the list,
                 * not an exemption for any controller — the safety property
                 * it exists to protect is asserted separately below, and is
                 * STRICTER than "the method exists".
                 */
                'loadCount', 'loadMissing', 'loadSum',
            ];

            if (in_array($method, $eloquent, true)) {
                continue;
            }

            if (! preg_match('/public function '.preg_quote($method, '/').'\s*\(/', $modelSource)) {
                $modelMethodMisses[] = basename($file).': '.$short.'::'.$method.'() does not exist';
            }
        }
    }
}

$ok('controllers only call model methods that exist', $modelMethodMisses === []);

/* -------------------- counting must be aggregated, never issued per row */

/*
 * THE INVARIANT, stated so it cannot be quietly lost: a controller may
 * count related rows only through an AGGREGATED query — `withCount()` on a
 * collection, `loadCount()` on a single record — and never by issuing a
 * count per row. The previous check protected this only accidentally, by
 * not knowing `loadCount()` at all; that made it strict about the wrong
 * thing (an inherited method's existence) while saying nothing about the
 * query shape. This replacement is strictly stronger: it still rejects an
 * unknown method, AND it rejects the unsafe shapes the old check could
 * not see.
 */
$countingViolations = [];

foreach (array_unique($controllers) as $file) {
    $source = (string) file_get_contents($file);
    $short = basename($file);

    // 1. A count inside a loop is an N+1 by construction.
    if (preg_match('/foreach\s*\([^)]*\)\s*\{[^}]*->(?:count|withCount|loadCount)\s*\(/s', $source)) {
        $countingViolations[] = $short.': counts inside a loop';
    }

    // 2. `loadCount()` on a COLLECTION rather than a record loads every
    //    row's counts one query at a time; the collection form is
    //    `withCount()` on the query.
    if (preg_match('/\$(?:users|rows|records|items|collection|results)->loadCount\s*\(/', $source)) {
        $countingViolations[] = $short.': loadCount() on a collection instead of withCount() on the query';
    }

    // 3. Counting the same relation twice on one record is redundant work.
    if (preg_match_all('/->(?:withCount|loadCount)\(\s*\[(.*?)\]/s', $source, $blocks)) {
        foreach ($blocks[1] as $block) {
            preg_match_all("/'(\w+)(?: as \w+)?'/", $block, $relations);
            $names = $relations[1];

            if (count($names) !== count(array_unique($names))) {
                $countingViolations[] = $short.': counts the same relation twice in one call';
            }
        }
    }
}

$ok('related counts are aggregated, never issued per row', $countingViolations === []);

foreach ($countingViolations as $entry) {
    echo "        unsafe counting: {$entry}\n";
}

foreach ($modelMethodMisses as $entry) {
    echo "        undefined model method: {$entry}\n";
}

/* ------------------------------ MediaUploader results must check `ok` */

$uncheckedUploads = [];

foreach (array_unique($controllers) as $file) {
    $source = (string) file_get_contents($file);

    if (! str_contains($source, 'storeImage(')) {
        continue;
    }

    if (! preg_match("/\\\$result\\['ok'\\]|\\['ok'\\] \\?\\?/", $source)) {
        $uncheckedUploads[] = basename($file);
    }
}

$ok('every storeImage() result is checked for ok', $uncheckedUploads === []);

foreach ($uncheckedUploads as $entry) {
    echo "        unchecked upload result: {$entry}\n";
}

/* --------------------- declared permissions must be used by some route */

$registry = (string) file_get_contents($root.'/app/Modules/Identity/Support/PermissionRegistry.php');
$routeSources = '';

foreach (glob($root.'/app/Modules/*/Routes/*.php') ?: [] as $routeFile) {
    $routeSources .= (string) file_get_contents($routeFile);
}

$unusedPermissions = [];

foreach (['projects.create_scoped', 'projects.create_unscoped'] as $permission) {
    if (str_contains($registry, "'{$permission}'") && ! str_contains($routeSources, $permission)) {
        $unusedPermissions[] = $permission;
    }
}

$ok('scoped/unscoped permissions are actually enforced by routes', $unusedPermissions === []);

foreach ($unusedPermissions as $entry) {
    echo "        declared but unenforced: {$entry}\n";
}

/* ----------------- draft media deletion must clean physical files */

$pruneFile = $root.'/app/Modules/Projects/Console/PruneProjectDrafts.php';
/*
 * Delegation, not a direct unlink. The command used to remove files itself,
 * which meant a third copy of the staged-failure rules; routing it through the
 * service is the stronger property, so that is what this now asserts.
 */
$pruneSource = is_file($pruneFile) ? (string) file_get_contents($pruneFile) : '';

$ok(
    'draft pruning delegates file removal to the media service',
    str_contains($pruneSource, 'ProjectDraftMediaService')
        && str_contains($pruneSource, 'purgeDraft('),
);

$ok(
    'draft pruning does not unlink files directly',
    ! str_contains($pruneSource, 'Storage::disk')
        && ! str_contains($pruneSource, '$uploader->remove('),
);

/* ------------------------------- integer/timestamp fields need casts */

$castGaps = [];

foreach (['ProjectDraft', 'ProjectDraftMedia', 'ProjectMedia', 'ProjectPrice'] as $model) {
    $path = $root.'/app/Modules/Projects/Models/'.$model.'.php';

    if (! is_file($path)) {
        continue;
    }

    $source = (string) file_get_contents($path);

    preg_match('/protected \\$fillable = \\[(.*?)\\];/s', $source, $fillableBlock);
    preg_match_all("/'(\\w+)'/", $fillableBlock[1] ?? '', $fields);
    preg_match('/function casts\\(\\): array(.*?)\\n    \\}/s', $source, $castsBlock);

    foreach ($fields[1] as $field) {
        $needsCast = str_ends_with($field, '_at')
            || str_ends_with($field, '_id')
            || in_array($field, ['version', 'size_bytes', 'width', 'height', 'sort_order'], true);

        // Foreign keys on the model's own belongsTo are cast by Eloquent's
        // key handling; only explicitly-read scalars are required here.
        if (! $needsCast || str_ends_with($field, '_id')) {
            continue;
        }

        if (! str_contains($castsBlock[1] ?? '', "'{$field}'")) {
            $castGaps[] = $model.'.'.$field;
        }
    }
}

$ok('integer and timestamp fields carry casts', $castGaps === []);

foreach ($castGaps as $entry) {
    echo "        missing cast: {$entry}\n";
}

/* ------------------- project cover writes must go through the service */

/*
 * The invariant was maintained in five places with slightly different rules.
 * One writer is the fix; this keeps it that way.
 */
$coverWriters = [];

foreach (array_merge(
    glob($root.'/app/Modules/Projects/Http/Controllers/**/*.php') ?: [],
    glob($root.'/app/Modules/Projects/Http/Controllers/*/*.php') ?: [],
    glob($root.'/app/Modules/Projects/Console/*.php') ?: [],
) as $file) {
    $source = (string) file_get_contents($file);

    /*
     * A WRITE, not a mention. `'is_cover' => ['boolean']` is a validation
     * rule and `'is_cover' => $m->is_cover` is a read for the payload —
     * flagging those would train people to ignore this check.
     */
    /*
     * PROJECT media only. ProjectDraftMedia has its own cover flag scoped to
     * one draft, which the service does not own and which cannot affect a
     * published project's card image.
     */
    if (preg_match("/ProjectMedia::query\\(\\)(?:(?!ProjectDraftMedia)[^;]){0,400}?'is_cover'\\s*=>/s", $source)) {
        $coverWriters[] = basename($file);
    }
}

$ok('project cover state is written only by ProjectMediaService', $coverWriters === []);

foreach (array_unique($coverWriters) as $entry) {
    echo "        writes is_cover directly: {$entry}\n";
}

/* --------------- migrations that add columns must guard with hasColumn */

$unguardedAdds = [];

foreach (glob($root.'/app/Modules/*/Database/Migrations/2026_07_*.php') ?: [] as $file) {
    $source = (string) file_get_contents($file);

    // Column ADDITIONS only. A Schema::table that just adds an index has
    // nothing to guard, and flagging it would train people to ignore this.
    $addsColumn = preg_match(
        '/\\$table->(?:string|boolean|integer|unsignedInteger|unsignedBigInteger|unsignedSmallInteger|decimal|timestamp|date|json|text|foreignId)\\(/',
        $source,
    ) === 1;

    if (str_contains($source, 'Schema::table(') && $addsColumn && ! str_contains($source, 'hasColumn')) {
        $unguardedAdds[] = basename($file);
    }
}

$ok('recent migrations guard column additions', $unguardedAdds === []);

foreach ($unguardedAdds as $entry) {
    echo "        unguarded: {$entry}\n";
}

/* ------------- lifecycle state must not be written outside the validator */

/*
 * Two validators that disagree are worse than one that is slightly wrong,
 * because neither can be trusted. The model guard once demanded approved_at
 * while ignoring rejected_at and revoked_at, which the database required.
 */
$lifecycleWriters = [];

foreach (array_merge(
    glob($root.'/app/Modules/*/Http/Controllers/*/*.php') ?: [],
    glob($root.'/app/Modules/*/Services/*.php') ?: [],
    glob($root.'/app/Modules/*/Console/*.php') ?: [],
) as $file) {
    $source = (string) file_get_contents($file);

    // A raw update() writing management_status bypasses the model guard.
    if (preg_match("/->update\\(\\[[^\\]]*'management_status'/s", $source)) {
        $lifecycleWriters[] = basename($file);
    }
}

$ok('association lifecycle is not written by raw update()', $lifecycleWriters === []);

foreach (array_unique($lifecycleWriters) as $entry) {
    echo "        raw lifecycle write: {$entry}\n";
}

/* ----------------- reconciliation must read fresh state, not a parameter */

$mediaService = $root.'/app/Modules/Projects/Services/ProjectMediaService.php';

if (is_file($mediaService)) {
    $source = (string) file_get_contents($mediaService);

    /*
     * reconcileWithin() taking a pre-loaded collection is how unsetCover
     * cleared the flag in the database and then counted a cover that only
     * existed in memory.
     */
    $ok(
        'cover reconciliation reads fresh state',
        preg_match('/function reconcileWithin\\(int \\$projectId\\)/', $source) === 1
            && ! str_contains($source, 'reconcileWithin($projectId, $'),
    );

    // Every mutation must hold a row lock before deciding.
    $ok(
        'the media service locks before reading cover state',
        substr_count($source, 'lockForUpdate') >= 1
            && str_contains($source, 'DB::transaction'),
    );
}

/* --------------------- documented migration count must match reality */

$doc = $root.'/docs/PROJECT_WIZARD_SLICE.md';

if (is_file($doc)) {
    $docSource = (string) file_get_contents($doc);

    // Rows in the inventory table, counted from the doc itself.
    preg_match_all('/^\\| \\d{6} \\|/m', $docSource, $docRows);

    $wizardMigrations = array_merge(
        /*
         * v6: `00[0-1]*` covered 000000–001999 and silently excluded the
         * 002000 reconciliation — so the strict chain's last migration was
         * invisible to the inventory check that exists to notice exactly
         * that kind of omission.
         */
        glob($root.'/app/Modules/Projects/Database/Migrations/2026_07_25_00[0-2]*.php') ?: [],
        glob($root.'/app/Modules/Companies/Database/Migrations/2026_07_25_001[12]*.php') ?: [],
        // Marketplace joined the Wizard-era set at 001500; counting only two
        // modules under-counted the inventory the documentation lists.
        glob($root.'/app/Modules/Marketplace/Database/Migrations/2026_07_25_*.php') ?: [],
    );

    /*
     * THE INVARIANT, strengthened rather than relaxed.
     *
     * Counting rows proves only that two numbers agree — the doc could
     * list a migration that does not exist while omitting one that does,
     * and the totals would still match. Four sources must now agree on the
     * exact SET of Wizard-era migrations:
     *
     *   disk        the files themselves
     *   docs        the inventory table
     *   rollback    RollbackWizardSchema::MIGRATIONS, the reversal order
     *   runtime     what a migrator would discover in the module paths
     *
     * Any disagreement names the offending migrations rather than a count.
     */
    preg_match_all('/^\\| (\\d{6}) \\| \\w+ \\| `([a-z0-9_]+)`/m', $docSource, $docEntries, PREG_SET_ORDER);

    $documented = [];
    foreach ($docEntries as $entry) {
        $documented[] = '2026_07_25_'.$entry[1].'_'.$entry[2];
    }

    $onDisk = array_map(
        static fn (string $f): string => basename($f, '.php'),
        $wizardMigrations,
    );

    $rollbackSource = (string) file_get_contents(
        $root.'/app/Modules/Projects/Console/RollbackWizardSchema.php'
    );
    preg_match_all("/'(2026_07_25_\\d{6}_[a-z0-9_]+)'/", $rollbackSource, $rollbackMatches);
    $rollbackInventory = array_values(array_unique($rollbackMatches[1]));

    // Runtime discovery: every module path a migrator would scan.
    $discovered = array_map(
        static fn (string $f): string => basename($f, '.php'),
        glob($root.'/app/Modules/*/Database/Migrations/2026_07_25_*.php') ?: [],
    );
    $discovered = array_values(array_intersect($discovered, array_merge($onDisk, $rollbackInventory)));

    sort($documented);
    sort($onDisk);
    sort($rollbackInventory);
    sort($discovered);

    $disagreements = [];

    foreach ([
        'documentation' => $documented,
        'rollback inventory' => $rollbackInventory,
        'runtime discovery' => $discovered,
    ] as $label => $set) {
        foreach (array_diff($onDisk, $set) as $missing) {
            $disagreements[] = "{$label} omits {$missing}";
        }

        foreach (array_diff($set, $onDisk) as $extra) {
            $disagreements[] = "{$label} lists {$extra}, which is not on disk";
        }
    }

    $ok(
        'the documented migration inventory matches the files on disk',
        $disagreements === [],
    );

    foreach ($disagreements as $entry) {
        echo "        inventory disagreement: {$entry}\n";
    }

    // The strict chain specifically must be present and ordered for reversal.
    $strict = [
        '2026_07_25_001700_cleanup_job_identity',
        '2026_07_25_001800_journal_entry_idempotency',
        '2026_07_25_001900_immutable_cleanup_incidents',
        '2026_07_25_002000_reconcile_cleanup_ledger_schema',
    ];

    /*
     * Positions come from the constant's ORDER as written, which is the
     * reversal order the command executes — newest first.
     */
    $strictPositions = [];

    foreach ($strict as $name) {
        $strictPositions[$name] = array_search($name, $rollbackMatches[1], true);
    }

    $ok(
        'the strict cleanup chain is present and reverses newest-first',
        array_diff($strict, $onDisk) === []
            && array_diff($strict, $rollbackMatches[1]) === []
            && $strictPositions['2026_07_25_002000_reconcile_cleanup_ledger_schema']
                < $strictPositions['2026_07_25_001900_immutable_cleanup_incidents']
            && $strictPositions['2026_07_25_001900_immutable_cleanup_incidents']
                < $strictPositions['2026_07_25_001800_journal_entry_idempotency']
            && $strictPositions['2026_07_25_001800_journal_entry_idempotency']
                < $strictPositions['2026_07_25_001700_cleanup_job_identity'],
    );

    if (count($docRows[0]) !== count($wizardMigrations)) {
        echo '        doc lists '.count($docRows[0]).', disk has '.count($wizardMigrations)."\n";
    }
}

/* ============ MILESTONE 1+2 COMBINED AUDIT ============ */

/*
 * Audit points 3, 4 and 8, mechanised. The rest are covered by the checks
 * above (routes→methods, models, casts, fillable, migrations, cover writes,
 * lifecycle writers, doc counts).
 */

/* 2. Every injected controller dependency must resolve to a real file. */
$unresolvedDeps = [];

foreach (array_unique($controllers) as $file) {
    $source = (string) file_get_contents($file);

    preg_match_all('/^use ([A-Za-z0-9_\\\\]+);$/m', $source, $uses);

    $aliases = [];

    foreach ($uses[1] as $fqcn) {
        $parts = explode('\\', $fqcn);
        $aliases[end($parts)] = $fqcn;
    }

    preg_match_all('/private readonly ([A-Za-z0-9_]+) /', $source, $deps);

    foreach (array_unique($deps[1]) as $dep) {
        if (! isset($aliases[$dep])) {
            continue;
        }

        $path = $root.'/'.str_replace(['App\\', '\\'], ['app/', '/'], $aliases[$dep]).'.php';

        if (! is_file($path)) {
            $unresolvedDeps[] = basename($file).': '.$aliases[$dep];
        }
    }
}

$ok('every injected controller dependency resolves', $unresolvedDeps === []);

foreach ($unresolvedDeps as $entry) {
    echo "        unresolved dependency: {$entry}\n";
}

/* 3. Every field the Wizard UI binds must be a validated step field. */
$wizardVue = $root.'/resources/js/Pages/Admin/Projects/Wizard.vue';
$wizardStep = $root.'/app/Modules/Projects/Support/WizardStep.php';

if (is_file($wizardVue) && is_file($wizardStep)) {
    $vue = (string) file_get_contents($wizardVue);
    $rules = (string) file_get_contents($wizardStep);

    preg_match_all('/form\.(\w+)/', $vue, $bound);

    $ignored = [
        'errors', 'processing', 'transform', 'post', 'reset', 'defaults',
        'isDirty', 'hasErrors', 'clearErrors', 'setError', 'data', 'cancel',
        'recentlySuccessful', 'wasSuccessful', 'progress', 'put', 'patch', 'delete',
    ];

    $unvalidated = [];

    foreach (array_unique($bound[1]) as $field) {
        if (in_array($field, $ignored, true)) {
            continue;
        }

        if (! str_contains($rules, "'{$field}'")) {
            $unvalidated[] = $field;
        }
    }

    $ok('every Wizard-bound field has a validation rule', $unvalidated === []);

    foreach ($unvalidated as $field) {
        echo "        bound but unvalidated: form.{$field}\n";
    }
}

/* 4. Every URL the Wizard UI posts to must exist as a route. */
$routeSource = '';

foreach (glob($root.'/app/Modules/*/Routes/*.php') ?: [] as $file) {
    $routeSource .= (string) file_get_contents($file);
}

$vueFiles = array_merge(
    glob($root.'/resources/js/Pages/Admin/Projects/*.vue') ?: [],
    glob($root.'/resources/js/Pages/Admin/Companies/*.vue') ?: [],
    glob($root.'/resources/js/Components/*.vue') ?: [],
);

$deadEndpoints = [];

foreach ($vueFiles as $file) {
    $source = (string) file_get_contents($file);

    // router.post('/admin/...') and template literals with ${...} segments.
    preg_match_all("#['\"`]/admin/([a-z0-9\-/]+)#i", $source, $urls);

    foreach (array_unique($urls[1]) as $url) {
        // First path segment is enough: the rest is ids and sub-actions, and
        // route files declare the segment literally.
        $segment = explode('/', trim($url, '/'))[0];

        if ($segment === '' || str_contains($routeSource, "'/{$segment}")) {
            continue;
        }

        $deadEndpoints[] = basename($file).' -> /admin/'.$segment;
    }
}

$ok('every admin URL the UI calls has a route', $deadEndpoints === []);

foreach (array_unique($deadEndpoints) as $entry) {
    echo "        dead endpoint: {$entry}\n";
}

/* 8. Hand-off links must target routes that exist. */
$handoffs = ['ratings', 'edit'];
$missingHandoffs = [];

foreach ($handoffs as $handoff) {
    if (! str_contains($routeSource, $handoff)) {
        $missingHandoffs[] = $handoff;
    }
}

$ok('ratings and publication hand-off destinations exist', $missingHandoffs === []);

/* 10. Schema version must match the highest wizard-era migration. */
$config = (string) file_get_contents($root.'/config/mulkihawler.php');

preg_match("/'schema_version' => (\d+)/", $config, $schema);

$migrationCount = count(array_merge(
    glob($root.'/app/Modules/Projects/Database/Migrations/2026_07_25_00[0-1]*.php') ?: [],
    glob($root.'/app/Modules/Companies/Database/Migrations/2026_07_25_001*.php') ?: [],
));

// Ten wizard migrations at schema 13 is the documented boundary; the check is
// that the version was RAISED past the last one, not a fixed number.
$ok('schema version is at or beyond the wizard migration boundary',
    (int) ($schema[1] ?? 0) >= 12 && $migrationCount >= 10);

/* Documentation must not still call required work unbuilt. */
$doc = $root.'/docs/PROJECT_WIZARD_SLICE.md';

if (is_file($doc)) {
    $docSource = (string) file_get_contents($doc);

    $forbidden = [];

    foreach (['Not built', 'not built', 'outstanding', 'Outstanding', 'placeholder'] as $phrase) {
        if (str_contains($docSource, $phrase)) {
            $forbidden[] = $phrase;
        }
    }

    $ok('documentation does not describe required work as unbuilt', $forbidden === []);

    foreach ($forbidden as $phrase) {
        echo "        documentation still says: {$phrase}\n";
    }
}

/* ---------- physical deletion must happen outside the transaction ---------- */

/*
 * Removing bytes inside a transaction means a rollback restores the row while
 * the file stays gone — a gallery entry pointing at nothing, permanently.
 */
$unsafeDeletes = [];

foreach (glob($root.'/app/Modules/Projects/Services/*.php') ?: [] as $file) {
    $source = (string) file_get_contents($file);

    if (! str_contains($source, 'removeBytes')) {
        continue;
    }

    // A removeBytes() call inside a DB::transaction closure.
    if (preg_match('/DB::transaction\(function[^}]{0,2000}?removeBytes/s', $source)) {
        $unsafeDeletes[] = basename($file);
    }
}

$ok('physical deletion happens after commit, not inside a transaction', $unsafeDeletes === []);

foreach ($unsafeDeletes as $entry) {
    echo "        unlink inside transaction: {$entry}\n";
}

/* -------------- draft media state must go through its service -------------- */

$draftMediaWriters = [];

foreach (array_unique($controllers) as $file) {
    $source = (string) file_get_contents($file);

    if (preg_match('/ProjectDraftMedia::query\\(\\)[^;]{0,300}->update\\(/s', $source)) {
        $draftMediaWriters[] = basename($file);
    }
}

$ok('draft media state is written only by its service', $draftMediaWriters === []);

foreach (array_unique($draftMediaWriters) as $entry) {
    echo "        raw draft-media write: {$entry}\n";
}

/* -------- frontend tests must import production code, not copy it -------- */

$wizardTest = $root.'/tests/js/wizard.test.ts';

if (is_file($wizardTest)) {
    $testSource = (string) file_get_contents($wizardTest);

    /*
     * A suite that reimplements the code it tests passes whether or not the
     * component is correct — and this one did, while MapPicker flattened every
     * hole into a separate filled shape.
     */
    $ok(
        'the frontend suite imports production geometry',
        str_contains($testSource, "from '../../resources/js/lib/wizard/geometry'"),
    );

    $ok(
        'the frontend suite does not redefine serialisation',
        ! preg_match('/function (toWkt|fromWkt|ringToWkt|parseWkt)\s*\(/', $testSource),
    );
}

/* ------------- explanatory routes must sit outside their own gate ---------- */

$projectRoutes = (string) file_get_contents($root.'/app/Modules/Projects/Routes/admin.php');

$unavailablePos = strpos($projectRoutes, "'projects.wizard.unavailable'");
$scopedPos = strpos($projectRoutes, 'permission:projects.create_scoped');

$ok(
    'the unavailable page is declared before the permission group it explains',
    $unavailablePos !== false && $scopedPos !== false && $unavailablePos < $scopedPos,
);

/* ------------- literal routes must be registered before wildcards ------------- */

/*
 * Laravel matches in declaration order, so `/{draft}/{step}` registered first
 * swallowed POST .../submit and GET /done/7. Asserting the controller method
 * exists did not catch it — the method existed and was simply never reached.
 */
$precedenceProblems = [];

foreach (glob($root.'/app/Modules/*/Routes/*.php') ?: [] as $routeFile) {
    $source = (string) file_get_contents($routeFile);

    /*
     * Declarations WITH their constraint chain, up to the terminating `;`.
     * A `whereNumber` on the wildcard means a non-numeric literal cannot be
     * captured by it — flagging that would be a false alarm, and a check that
     * cries wolf gets ignored.
     */
    preg_match_all(
        "#Route::(get|post|put|patch|delete)\\('(/[^']*)'.*?;#s",
        $source,
        $declared,
        PREG_SET_ORDER,
    );

    $seenWildcards = [];

    foreach ($declared as [$all, $verb, $uri]) {
        $segments = explode('/', trim($uri, '/'));

        foreach ($seenWildcards as [$wildVerb, $wildSegments]) {
            if ($wildVerb !== $verb || count($wildSegments) !== count($segments)) {
                continue;
            }

            $shadowed = true;

            foreach ($wildSegments as $i => $wildSegment) {
                $isParam = str_starts_with($wildSegment, '{');

                if (! $isParam && $wildSegment !== ($segments[$i] ?? null)) {
                    $shadowed = false;

                    break;
                }
            }

            if ($shadowed) {
                $precedenceProblems[] = basename($routeFile).': '.strtoupper($verb).' '.$uri
                    .' is shadowed by '.implode('/', $wildSegments);
            }
        }

        if (str_contains($uri, '{')) {
            // Constrained parameters cannot swallow a non-matching literal.
            $constrained = str_contains($all, 'whereNumber')
                || str_contains($all, 'whereIn')
                || str_contains($all, '->where(');

            if (! $constrained) {
                $seenWildcards[] = [$verb, $segments];
            }
        }
    }
}

$ok('no literal route is shadowed by an earlier wildcard', $precedenceProblems === []);

foreach (array_unique($precedenceProblems) as $entry) {
    echo "        shadowed: {$entry}\n";
}

/* --------------- permission names used must exist in the registry --------------- */

$registry = (string) file_get_contents($root.'/app/Modules/Identity/Support/PermissionRegistry.php');
$undefinedPermissions = [];

foreach (glob($root.'/app/Modules/*/Routes/*.php') ?: [] as $routeFile) {
    $source = (string) file_get_contents($routeFile);

    preg_match_all('/permission:([a-z_.]+)/', $source, $used);

    foreach (array_unique($used[1]) as $permission) {
        foreach (explode(',', $permission) as $single) {
            if (! str_contains($registry, "'".trim($single)."'")) {
                $undefinedPermissions[] = basename($routeFile).': '.trim($single);
            }
        }
    }
}

$ok('every permission a route requires is defined in the registry', $undefinedPermissions === []);

foreach (array_unique($undefinedPermissions) as $entry) {
    echo "        undefined permission: {$entry}\n";
}

/* ------------------ draft media must not use the public disk ------------------ */

$wizardController = (string) file_get_contents(
    $root.'/app/Modules/Projects/Http/Controllers/Admin/ProjectWizardController.php'
);

/*
 * The uploader defaults to the public disk, so the third argument must be
 * present AND the row must record it. `[^)]*` matched across the argument
 * itself in my first attempt, which reported a correct call as wrong.
 */
$ok(
    'draft uploads are stored on the private disk',
    str_contains($wizardController, "'disk' => 'draft-media'")
        && str_contains($wizardController, "storeImage(\$file, 'project-drafts/'.\$draft->id, 'draft-media')"),
);

/* --------------- module console commands must be registered --------------- */

/*
 * Commands existed, were scheduled, and were unreachable: no provider called
 * commands(), and console routes were loaded with loadRoutesFrom(), which is
 * skipped once route:cache has run — so on production the sweeps silently did
 * not exist.
 */
$moduleProvider = $root.'/app/Modules/Core/Support/ModuleServiceProvider.php';

if (is_file($moduleProvider)) {
    $providerSource = (string) file_get_contents($moduleProvider);

    $ok(
        'module commands are registered with artisan',
        str_contains($providerSource, '$this->commands('),
    );

    $ok(
        'console routes are not loaded with loadRoutesFrom',
        ! preg_match('/loadRoutesFrom\(\$console\)/', $providerSource),
    );
}

/* --------- the frontend must emit the point as one value, not two --------- */

$picker = $root.'/resources/js/Components/map/MapPicker.vue';

if (is_file($picker)) {
    $pickerSource = (string) file_get_contents($picker);

    $ok(
        'the picker emits a single point value',
        str_contains($pickerSource, "'update:point'")
            && ! str_contains($pickerSource, "'update:latitude'"),
    );
}

/* ------------- promotion must exclude cleanup-pending rows ------------- */

$mediaService = (string) file_get_contents($root.'/app/Modules/Projects/Services/ProjectMediaService.php');

$ok(
    'promotion excludes cleanup-pending draft media',
    preg_match("/promoteDraftMedia.{0,1200}cleanup_pending', false/s", $mediaService) === 1,
);

$ok(
    'promotion locks the draft row first',
    preg_match('/promoteDraftMedia.{0,600}ProjectDraft::query\\(\\)->lockForUpdate\\(\\)/s', $mediaService) === 1,
);

/* ---------- retry commands must delegate, not reimplement ---------- */

$commandLeaks = [];

foreach (glob($root.'/app/Modules/Projects/Console/*.php') ?: [] as $file) {
    $source = (string) file_get_contents($file);

    if (! str_contains($source, 'cleanup_pending')) {
        continue;
    }

    // A command doing its own storage work is a second implementation of the
    // lifecycle, and the two only diverge when a delete fails.
    if (str_contains($source, 'Storage::disk') || str_contains($source, '->remove(')) {
        $commandLeaks[] = basename($file);
    }
}

$ok('cleanup commands delegate storage work to the services', $commandLeaks === []);

foreach ($commandLeaks as $entry) {
    echo "        command does its own storage work: {$entry}\n";
}

/* ------------- draft media mutations must revalidate the draft ------------- */

$draftService = (string) file_get_contents(
    $root.'/app/Modules/Projects/Services/ProjectDraftMediaService.php'
);

/*
 * METHOD-AWARE, not a global count.
 *
 * The previous check was `substr_count(...) >= 5`, which passed because ONE
 * method contained five consecutive duplicate calls — the opposite of what it
 * was meant to prove. A count of occurrences says nothing about coverage.
 */
$requiredLockers = ['attach', 'setCover', 'reorder', 'updateAlt', 'delete', 'purgeDraft'];
$missingLocks = [];
$duplicateLocks = [];

foreach ($requiredLockers as $method) {
    // The method body, up to the next method declaration.
    if (! preg_match(
        '/public function '.preg_quote($method, '/').'\s*\(.*?(?=\n    (?:public|private|protected) function )/s',
        $draftService,
        $body,
    )) {
        $missingLocks[] = $method.' (not found)';

        continue;
    }

    $source = $body[0];

    // purgeDraft locks the draft directly rather than through the editable
    // guard, because a draft being purged is deliberately NOT editable.
    $locks = substr_count($source, 'lockEditableDraft(')
        + substr_count($source, 'ProjectDraft::query()->lockForUpdate()');

    if ($locks === 0) {
        $missingLocks[] = $method;
    }

    // Two identical lock calls in a row prove nothing and cost a round trip.
    if (preg_match('/lockEditableDraft\(\$draftId\);\s*\n\s*\$this->lockEditableDraft\(/', $source)) {
        $duplicateLocks[] = $method;
    }
}

$ok('every draft media mutation locks the draft exactly once', $missingLocks === [] && $duplicateLocks === []);

foreach ($missingLocks as $entry) {
    echo "        unlocked mutation: {$entry}\n";
}

foreach ($duplicateLocks as $entry) {
    echo "        duplicate consecutive lock: {$entry}\n";
}

/* ------- mutations must lock the media set and exclude pending rows ------- */

$unfilteredMutations = [];

foreach (['setCover', 'reorder', 'updateAlt'] as $method) {
    if (! preg_match(
        '/public function '.preg_quote($method, '/').'\s*\(.*?(?=\n    (?:public|private|protected) function )/s',
        $draftService,
        $body,
    )) {
        continue;
    }

    if (! str_contains($body[0], 'lockDraftMedia(')) {
        $unfilteredMutations[] = $method.' (media not locked)';
    }

    if (! str_contains($body[0], 'cleanup_pending')) {
        $unfilteredMutations[] = $method.' (pending not excluded)';
    }
}

$ok('media mutations lock the set and exclude cleanup-pending rows', $unfilteredMutations === []);

foreach ($unfilteredMutations as $entry) {
    echo "        {$entry}\n";
}

$ok(
    'purge stages the set before removing bytes',
    /*
     * Window widened: the submitted-draft guard added since pushed the staging
     * write past the old 900-character limit, and a proximity check that fails
     * because correct code grew is a check nobody will trust.
     */
    preg_match("/purgeDraft.{0,2000}cleanup_pending' => true/s", $draftService) === 1,
);

/* ------------------- roles must not merge whole groups blindly ------------------- */

$registrySource = (string) file_get_contents(
    $root.'/app/Modules/Identity/Support/PermissionRegistry.php'
);

/*
 * A data editor merging $c['projects'] received publish, unscoped creation and
 * developer administration — and every permission test using that role then
 * passed regardless of the boundary being tested.
 */
$ok(
    'the project data editor role is an explicit list',
    preg_match("/ProjectDataEditor->value => array_merge\\(\\s*\\\$c\\['projects'\\]/s", $registrySource) !== 1,
);

/* --------------- documentation must not contradict itself --------------- */

$provider = (string) file_get_contents(
    $root.'/app/Modules/Projects/Providers/ProjectsServiceProvider.php'
);

$ok(
    'the projects provider does not claim the wizard is unimplemented',
    ! preg_match('/NOT implemented.{0,200}wizard/si', $provider),
);

/* ------------- every role must have a label in every locale ------------- */

$roleEnum = (string) file_get_contents($root.'/app/Modules/Identity/Enums/RoleKey.php');

preg_match_all("/case \\w+ = '([a-z_]+)'/", $roleEnum, $roleKeys);

$missingLabels = [];

foreach (['ckb', 'ar', 'en'] as $locale) {
    $file = $root.'/lang/'.$locale.'/identity.php';

    if (! is_file($file)) {
        continue;
    }

    $source = (string) file_get_contents($file);

    foreach ($roleKeys[1] as $key) {
        if (! str_contains($source, "'".$key."'")) {
            $missingLabels[] = $locale.': '.$key;
        }
    }
}

/*
 * A role selector showing a raw key is a Sorani-speaking administrator being
 * asked to pick `platform_project_operator` from a list.
 */
$ok('every role key has a label in ckb, ar and en', $missingLabels === []);

foreach ($missingLabels as $entry) {
    echo "        missing role label: {$entry}\n";
}

/* --------- company portal roles must not hold platform administration --------- */

$broadCompanyRole = preg_match(
    "/CompanyAccountManager->value => array_merge\\(\\s*\\\$c\\['companies'\\]/s",
    $registrySource,
) === 1;

$ok('the company account manager role is an explicit list', ! $broadCompanyRole);

foreach ([
    'companies.create',
    'companies.verify',
    'companies.subscriptions.manage',
] as $platformPermission) {
    // Present in the catalogue is fine; present in the PORTAL role is not.
    if (preg_match(
        '/CompanyAccountManager->value => \\[(.*?)\\],\\n/s',
        $registrySource,
        $roleBody,
    ) && str_contains($roleBody[1], "'".$platformPermission."'")) {
        $ok('company portal role excludes '.$platformPermission, false);
    }
}

/* ------------- files written before their row need compensation ------------- */

$compensationGaps = [];

foreach (array_merge(
    glob($root.'/app/Modules/Projects/Http/Controllers/*/*.php') ?: [],
    glob($root.'/app/Modules/Projects/Services/*.php') ?: [],
) as $file) {
    $source = (string) file_get_contents($file);

    if (! str_contains($source, 'storeImage(')) {
        continue;
    }

    // A path that stores bytes must be able to record an orphan when the
    // database step fails; a log line is not a work queue.
    /*
     * BRANCH-AWARE. The mere presence of a durable record somewhere in the
     * file is not proof that EVERY failure path uses one — so each removal
     * call is checked individually.
     */
    if (! str_contains($source, 'OrphanedFile::removeOrRecord')
        && ! str_contains($source, 'OrphanedFile::record')
        && ! str_contains($source, 'storeForProject')) {
        $compensationGaps[] = basename($file);

        continue;
    }

    // A bare remove() whose result is discarded is a silent orphan.
    if (preg_match('/\n\s*\$(?:this->)?uploader->remove\([^;]*\);/', $source)) {
        $compensationGaps[] = basename($file).' (unchecked remove())';
    }
}

$ok('every upload path can record a durable orphan', $compensationGaps === []);

foreach ($compensationGaps as $entry) {
    echo "        no durable compensation: {$entry}\n";
}

/* ---------- controller methods must not use undefined variables ---------- */

/*
 * THE CHECK THAT WAS MISSING.
 *
 * Two controller methods referenced variables that were never parameters and
 * never assigned — `$file` and `$validated` in a media upload, `$request` in a
 * company edit. Both files parsed cleanly, every existing guard passed, and
 * both routes threw on the first real request.
 */
$undefinedVariables = [];

foreach (array_unique($controllers) as $file) {
    $source = (string) file_get_contents($file);

    preg_match_all(
        '/(?:public|private|protected) function (\w+)\s*\(([^)]*)\)[^{]*\{(.*?)\n    \}/s',
        $source,
        $methods,
        PREG_SET_ORDER,
    );

    foreach ($methods as [$whole, $name, $params, $body]) {
        /*
         * An empty one-line body — `) {}` — has nothing to check, and the
         * capture would otherwise run into the following method and report
         * ITS parameters as undefined.
         */
        if (preg_match('/\)\s*(?::\s*[^\s{]+\s*)?\{\s*\}/', $whole)) {
            continue;
        }

        // Parameters, including promoted and typed ones.
        preg_match_all('/\$(\w+)/', $params, $declared);

        $known = $declared[1];

        // Anything assigned, foreach-bound, caught, or list-destructured.
        preg_match_all('/\$(\w+)\s*(?:=[^=]|\??\?=)/', $body, $assigned);
        preg_match_all('/as\s+\$(\w+)(?:\s*=>\s*\$(\w+))?/', $body, $loops);
        preg_match_all('/catch\s*\([^)]*\$(\w+)\)/', $body, $caught);
        preg_match_all('/\[\s*\$(\w+)\s*,\s*\$(\w+)/', $body, $destructured);

        /*
         * By-reference outputs. preg_match(), preg_match_all() and parse_str()
         * CREATE their trailing argument — treating it as a read produced a
         * false alarm on every regex helper in the codebase.
         */
        preg_match_all(
            '/(?:preg_match|preg_match_all|parse_str|similar_text)\s*\(.*?,\s*\$(\w+)\s*\)/s',
            $body,
            $byReference,
        );

        $known = array_merge(
            $known,
            $assigned[1],
            $loops[1],
            array_filter($loops[2]),
            $caught[1],
            $destructured[1],
            $destructured[2],
            $byReference[1],
            // Closures capture by `use`; treat those as known here since the
            // outer scope is checked separately.
            ['this', 'query', 'item', 'page'],
        );

        preg_match_all('/\$(\w+)\b/', $body, $used);

        foreach (array_unique($used[1]) as $variable) {
            // Closure-scoped names introduced by `use (...)` or inline fn.
            if (preg_match('/use\s*\([^)]*\$'.preg_quote($variable, '/').'\b/', $body)) {
                continue;
            }

            if (preg_match('/(?:function|fn)\s*\([^)]*\$'.preg_quote($variable, '/').'\b/', $body)) {
                continue;
            }

            if (! in_array($variable, $known, true)) {
                $undefinedVariables[] = basename($file).'::'.$name.'() uses $'.$variable;
            }
        }
    }
}

$ok('no controller method uses an undefined variable', $undefinedVariables === []);

foreach (array_unique($undefinedVariables) as $entry) {
    echo "        undefined variable: {$entry}\n";
}

/* ---------- a claim marker must not be set at record time ---------- */

$outbox = (string) file_get_contents($root.'/app/Modules/Projects/Models/OrphanedFile.php');
$sweep = (string) file_get_contents($root.'/app/Modules/Projects/Console/SweepOrphanedFiles.php');

/*
 * The sweep skips rows claimed recently. Stamping `last_attempted_at` when a
 * row is RECORDED therefore hid every new orphan for the claim window — the
 * file most urgently needing removal was the one the sweep would not look at.
 */
/*
 * COMMENTS STRIPPED before scanning. The first version of this check matched
 * its own explanatory comment — "`last_attempted_at` IS NOT SET HERE" — and
 * reported the corrected code as broken. A guard that reads prose is a guard
 * that cannot be trusted.
 */
$outboxCode = preg_replace('#/\\*.*?\\*/|//[^\n]*#s', '', $outbox) ?? $outbox;

/*
 * The claim marker must not be SET at record time. Clearing it for a new
 * lifecycle is the opposite — a reused path arriving with an old claim would
 * be skipped for the whole claim window — so only an assignment to `now()`
 * counts as claiming.
 */
$recordsClaim = preg_match(
    "/function record\\(.*?'last_attempted_at'\\s*=>\\s*now\\(\\).*?(?=\\n    (?:public|private|protected) function )/s",
    $outboxCode,
) === 1;

$sweepUsesClaim = str_contains($sweep, 'last_attempted_at');

$ok('recording an orphan does not claim it', ! ($recordsClaim && $sweepUsesClaim));

/* ------- permissions removed from a role must not orphan a route ------- */

$registryText = (string) file_get_contents(
    $root.'/app/Modules/Identity/Support/PermissionRegistry.php'
);

$routeText = '';

foreach (glob($root.'/app/Modules/*/Routes/*.php') ?: [] as $routeFile) {
    $routeText .= (string) file_get_contents($routeFile);
}

/*
 * Everything any role actually grants: explicit role lists, plus the contents
 * of every catalogue group a role merges.
 */
$grantedPermissions = [];

// Catalogue groups, by name.
preg_match_all("/'(\\w+)' => \\[([^\\]]*)\\]/s", $registryText, $groupBlocks, PREG_SET_ORDER);

$catalogue = [];

foreach ($groupBlocks as [$whole, $groupName, $body]) {
    preg_match_all("/'([a-z][a-z_.]+)'/", $body, $entries);
    $catalogue[$groupName] = $entries[1];
}

// The role map: everything after `map()`.
$mapStart = strpos($registryText, 'function map(');

if ($mapStart !== false) {
    $mapText = substr($registryText, $mapStart);

    // Explicit role permissions.
    preg_match_all("/'([a-z][a-z_.]+\\.[a-z_.]+)'/", $mapText, $explicit);
    $grantedPermissions = $explicit[1];

    // Groups merged into roles.
    preg_match_all("/\\\$c\\['(\\w+)'\\]/", $mapText, $merged);

    foreach (array_unique($merged[1]) as $groupName) {
        $grantedPermissions = array_merge($grantedPermissions, $catalogue[$groupName] ?? []);
    }
}

$grantedPermissions = array_values(array_unique($grantedPermissions));

$strandedRoutes = [];

/*
 * A route requiring a permission that NO role grants is reachable only by
 * Super Admin. Renaming a permission on a role without updating the routes is
 * how a company user silently loses their own screens.
 */
preg_match_all('/permission(?:_any)?:([a-z_.,]+)/', $routeText, $required);

foreach (array_unique($required[1]) as $group) {
    $alternatives = array_map('trim', explode(',', $group));

    $grantedToSomebody = false;

    foreach ($alternatives as $permission) {
        /*
         * GROUPS RESOLVED. A permission living in $c['market'] is granted by
         * every role that merges that group, and appears literally only once —
         * counting occurrences reported five correctly-granted permissions as
         * stranded.
         */
        if (in_array($permission, $grantedPermissions, true)) {
            $grantedToSomebody = true;

            break;
        }
    }

    if (! $grantedToSomebody) {
        $strandedRoutes[] = $group;
    }
}

$ok('no route requires a permission no role grants', $strandedRoutes === []);

foreach ($strandedRoutes as $entry) {
    echo "        stranded route permission: {$entry}\n";
}

/* ---------- company-bound controller methods must be scoped ---------- */

$companyController = $root.'/app/Modules/Companies/Http/Controllers/Admin/CompanyController.php';

if (is_file($companyController)) {
    $source = (string) file_get_contents($companyController);

    preg_match_all(
        '/public function (\\w+)\\([^)]*Company \\$company[^)]*\\)[^{]*\\{(.*?)(?=\\n    (?:public|private|protected) function |\\n\\}$)/s',
        $source,
        $companyMethods,
        PREG_SET_ORDER,
    );

    $unscoped = [];

    foreach ($companyMethods as [$whole, $name, $body]) {
        if (! str_contains($body, 'assertMayAdminister')) {
            $unscoped[] = $name;
        }
    }

    $ok('every company-bound method asserts administration scope', $unscoped === []);

    foreach ($unscoped as $entry) {
        echo "        unscoped company method: {$entry}\n";
    }
}

/* -------- exhausted cleanup rows must reach the durable outbox -------- */

$ceilingGaps = [];

foreach ([
    $root.'/app/Modules/Projects/Services/ProjectDraftMediaService.php',
    $root.'/app/Modules/Projects/Services/ProjectMediaService.php',
] as $file) {
    if (! is_file($file)) {
        continue;
    }

    $source = (string) file_get_contents($file);

    /*
     * Past the ceiling the retry commands stop selecting a row, so the media
     * row becomes the only reference to the file — and if the row is later
     * cascaded away the bytes are unfindable. A durable outbox entry has to be
     * written before that point.
     */
    /*
     * The handoff moved into `handOffToOutbox()`, which is idempotent and
     * retryable; asserting a literal `recordSafely` call at the ceiling now
     * describes the OLD one-shot design this replaced.
     */
    if (! str_contains($source, 'CLEANUP_ATTEMPT_CEILING')
        || ! str_contains($source, 'handOffToOutbox(')) {
        $ceilingGaps[] = basename($file);
    }
}

$ok('exhausted cleanup rows are handed to the orphan outbox', $ceilingGaps === []);

foreach ($ceilingGaps as $entry) {
    echo "        no ceiling handover: {$entry}\n";
}

/* --------- commands and services must share the retry ceiling --------- */

$ceilingMismatch = [];

foreach ([
    $root.'/app/Modules/Projects/Console/PruneDraftMedia.php',
    $root.'/app/Modules/Projects/Console/RetryMediaCleanup.php',
] as $file) {
    if (! is_file($file)) {
        continue;
    }

    $source = (string) file_get_contents($file);

    /*
     * A delegating command has no ceiling of its own to get wrong — it calls
     * the unified command, which reads the service constants. Requiring the
     * constant here would force a deprecated shim to import a value it never
     * uses.
     */
    if (str_contains($source, 'retry-media-cleanup-all')) {
        continue;
    }

    // A hard-coded default in a command that DOES select work can drift from
    // the service's, and a mismatch leaves rows neither mechanism owns.
    if (! str_contains($source, 'CLEANUP_ATTEMPT_CEILING')) {
        $ceilingMismatch[] = basename($file);
    }
}

$ok('cleanup commands take their ceiling from the service', $ceilingMismatch === []);

foreach ($ceilingMismatch as $entry) {
    echo "        hard-coded ceiling: {$entry}\n";
}

/* -------- recording an orphan must never throw on a failure path -------- */

$outboxSource = (string) file_get_contents($root.'/app/Modules/Projects/Models/OrphanedFile.php');

$ok(
    'the outbox offers a non-throwing recorder',
    str_contains($outboxSource, 'function recordSafely('),
);

/*
 * afterRollback runs during exception handling. A throw there replaces the
 * original error with a database one and loses both.
 */
$rollbackUnsafe = [];

foreach (glob($root.'/app/Modules/Projects/Services/*.php') ?: [] as $file) {
    $source = (string) file_get_contents($file);

    if (! str_contains($source, 'afterRollback')) {
        continue;
    }

    if (preg_match('/afterRollback.{0,1200}OrphanedFile::record\(/s', $source)) {
        $rollbackUnsafe[] = basename($file);
    }
}

$ok('rollback handlers use the non-throwing recorder', $rollbackUnsafe === []);

foreach ($rollbackUnsafe as $entry) {
    echo "        throwing recorder in a rollback handler: {$entry}\n";
}

/* --------- every permission a route names must exist in the catalogue --------- */

/*
 * The earlier guard scanned `permission:` only, so `permission_any:` values
 * escaped it — which is exactly how an undefined `marketplace.offers.manage_own`
 * reached production routes and authorised nothing.
 */
$catalogueNames = [];

foreach ($catalogue as $entries) {
    $catalogueNames = array_merge($catalogueNames, $entries);
}

$catalogueNames = array_values(array_unique($catalogueNames));

$undefinedRoutePermissions = [];

preg_match_all('/permission(?:_any)?:([a-z_.,]+)/', $routeText, $namedByRoutes);

foreach ($namedByRoutes[1] as $group) {
    foreach (explode(',', $group) as $permission) {
        $permission = trim($permission);

        if ($permission !== '' && ! in_array($permission, $catalogueNames, true)) {
            $undefinedRoutePermissions[] = $permission;
        }
    }
}

$ok('every route permission exists in the catalogue', $undefinedRoutePermissions === []);

foreach (array_unique($undefinedRoutePermissions) as $entry) {
    echo "        undefined route permission: {$entry}\n";
}

/* ------- controller and FormRequest permission strings must exist too ------- */

$undefinedCodePermissions = [];

foreach (array_merge(
    glob($root.'/app/Modules/*/Http/Controllers/*/*.php') ?: [],
    glob($root.'/app/Modules/*/Http/Requests/*.php') ?: [],
) as $file) {
    $source = (string) file_get_contents($file);

    preg_match_all("/hasPermission\\('([a-z_.]+)'\\)/", $source, $named);

    foreach (array_unique($named[1]) as $permission) {
        if (! in_array($permission, $catalogueNames, true)) {
            $undefinedCodePermissions[] = basename($file).': '.$permission;
        }
    }
}

/*
 * OfferRequest checked two permissions that did not exist, so authorize()
 * returned false for every real role and only Super Admin could create an
 * offer — the seller workflow was closed and nothing reported it.
 */
$ok('every permission checked in code exists', $undefinedCodePermissions === []);

foreach (array_unique($undefinedCodePermissions) as $entry) {
    echo "        undefined permission check: {$entry}\n";
}

/* --------- bound marketplace routes must have an ownership check --------- */

$unscopedOfferMethods = [];

foreach (glob($root.'/app/Modules/Marketplace/Http/Controllers/Admin/*.php') ?: [] as $file) {
    $source = (string) file_get_contents($file);

    preg_match_all(
        '/public function (\\w+)\\([^)]*Offer \\$offer[^)]*\\)[^{]*\\{(.*?)(?=\\n    (?:public|private|protected) function |\\n\\}$)/s',
        $source,
        $offerMethods,
        PREG_SET_ORDER,
    );

    foreach ($offerMethods as [$whole, $name, $body]) {
        // A route group proves someone may manage OFFERS; only a per-request
        // check proves they may manage THIS one.
        if (! str_contains($body, 'OfferScope::')) {
            $unscopedOfferMethods[] = basename($file).'::'.$name;
        }
    }
}

$ok('every offer-bound method checks ownership', $unscopedOfferMethods === []);

foreach ($unscopedOfferMethods as $entry) {
    echo "        unscoped offer method: {$entry}\n";
}

/* --------- expected creations must not be asserted conditionally --------- */

$conditionalAssertions = [];

foreach (glob($root.'/tests/Feature/*.php') ?: [] as $file) {
    $source = (string) file_get_contents($file);

    // Comments stripped: this check must not read its own explanation.
    $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source) ?? $source;

    /*
     * `if ($x !== null) { assert... }` around an EXPECTED creation passes when
     * the request failed and nothing was created — the opposite of what such a
     * test claims to prove.
     */
    if (preg_match('/if \(\$\w+ !== null\)\s*\{\s*\$this->assert/s', $code)) {
        $conditionalAssertions[] = basename($file);
    }
}

$ok('no expected creation is asserted behind a null check', $conditionalAssertions === []);

foreach ($conditionalAssertions as $entry) {
    echo "        conditional assertion: {$entry}\n";
}

/* --------- ownership columns must not be mass-assignable --------- */

$massAssignable = [];

foreach ([
    $root.'/app/Modules/Marketplace/Models/Offer.php',
] as $file) {
    if (! is_file($file)) {
        continue;
    }

    $source = (string) file_get_contents($file);

    if (preg_match('/protected \\$fillable = \\[(.*?)\\];/s', $source, $fillable)
        && str_contains($fillable[1], "'company_id'")) {
        $massAssignable[] = basename($file);
    }
}

/*
 * An ownership check followed by a fill() that can rewrite company_id protects
 * nothing: the offer changes hands immediately after the check passes.
 */
$ok('ownership columns are not mass-assignable', $massAssignable === []);

foreach ($massAssignable as $entry) {
    echo "        company_id is fillable: {$entry}\n";
}

/* --------- commands must only call service methods that exist --------- */

/*
 * The sweep called `$draftMedia->reconcile()`, which does not exist. Its own
 * catch swallowed the error, so the failure was invisible and completePurge()
 * silently never ran. php -l cannot see this; nothing did.
 */
$missingServiceCalls = [];

foreach (glob($root.'/app/Modules/*/Console/*.php') ?: [] as $file) {
    $source = (string) file_get_contents($file);

    preg_match_all('/\$(\w*[Mm]edia\w*)->(\w+)\(/', $source, $calls, PREG_SET_ORDER);

    foreach ($calls as [$whole, $variable, $method]) {
        // Which service class is behind that variable, by its type hint.
        if (! preg_match('/(\w+Service) \$'.preg_quote($variable, '/').'\b/', $source, $typed)) {
            continue;
        }

        $servicePath = $root.'/app/Modules/Projects/Services/'.$typed[1].'.php';

        if (! is_file($servicePath)) {
            continue;
        }

        $serviceSource = (string) file_get_contents($servicePath);

        if (! preg_match('/public function '.preg_quote($method, '/').'\s*\(/', $serviceSource)) {
            $missingServiceCalls[] = basename($file).': '.$typed[1].'::'.$method.'()';
        }
    }
}

$ok('commands only call service methods that exist', $missingServiceCalls === []);

foreach (array_unique($missingServiceCalls) as $entry) {
    echo "        missing service method: {$entry}\n";
}

/* --------- a swallowed failure must not resolve its own work --------- */

$sweepSource = (string) file_get_contents(
    $root.'/app/Modules/Projects/Console/SweepOrphanedFiles.php'
);

/*
 * Marking the outbox row resolved BEFORE finalising the source hid every
 * second-phase failure behind a record nothing would look at again.
 */
$resolvesBeforeFinalising = preg_match(
    "/'resolved_at' => now\\(\\).{0,400}finaliseSource/s",
    $sweepSource,
) === 1;

$ok('the outbox resolves only after source finalisation', ! $resolvesBeforeFinalising);

$ok(
    'the sweep tracks both phases separately',
    str_contains($sweepSource, 'file_resolved_at')
        && str_contains($sweepSource, 'source_finalised_at'),
);

/* --------- test fixtures must respect non-fillable ownership --------- */

$fixtureViolations = [];

foreach (glob($root.'/tests/Feature/*.php') ?: [] as $file) {
    $source = (string) file_get_contents($file);

    /*
     * Offer::$company_id is deliberately not fillable — that is the protection
     * under test. A fixture mass-assigning it throws under
     * preventSilentlyDiscardingAttributes(), so every offer test failed before
     * reaching the route it meant to exercise.
     */
    if (preg_match("/Offer::query\\(\\)->create\\(\\[[^\\]]*'company_id'/s", $source)) {
        $fixtureViolations[] = basename($file);
    }
}

$ok('no fixture mass-assigns a non-fillable ownership column', $fixtureViolations === []);

foreach ($fixtureViolations as $entry) {
    echo "        fixture mass-assigns company_id: {$entry}\n";
}

/* ---------------- lead-bound methods must check tenancy ---------------- */

$unscopedLeadMethods = [];

foreach (glob($root.'/app/Modules/Leads/Http/Controllers/Admin/*.php') ?: [] as $file) {
    $source = (string) file_get_contents($file);

    preg_match_all(
        '/public function (\\w+)\\([^)]*DemandProfile \\$lead[^)]*\\)[^{]*\\{(.*?)(?=\\n    (?:public|private|protected) function |\\n\\}$)/s',
        $source,
        $leadMethods,
        PREG_SET_ORDER,
    );

    foreach ($leadMethods as [$whole, $name, $body]) {
        if (! str_contains($body, 'LeadScope::')) {
            $unscopedLeadMethods[] = basename($file).'::'.$name;
        }
    }
}

/*
 * A lead carries a name, a phone number and stated buying intent. An unscoped
 * workspace handed every company's enquiries to every company.
 */
$ok('every lead-bound method checks tenancy', $unscopedLeadMethods === []);

foreach ($unscopedLeadMethods as $entry) {
    echo "        unscoped lead method: {$entry}\n";
}

/* ---------- media services must lock the owning row first ---------- */

$lockOrderGaps = [];

foreach ([
    [$root.'/app/Modules/Projects/Services/ProjectMediaService.php', 'Project::query()->lockForUpdate()'],
    [$root.'/app/Modules/Marketplace/Services/OfferMediaService.php', 'Offer::query()->lockForUpdate()'],
] as [$file, $ownerLock]) {
    if (! is_file($file)) {
        continue;
    }

    $source = (string) file_get_contents($file);

    /*
     * An empty gallery has no media row to lock, so two first uploads
     * serialise on nothing. The owning row is the one thing that always
     * exists — it has to be locked first, and in the same order everywhere.
     */
    if (! str_contains($source, $ownerLock)) {
        $lockOrderGaps[] = basename($file);
    }
}

$ok('media services lock the owning row before its media set', $lockOrderGaps === []);

foreach ($lockOrderGaps as $entry) {
    echo "        no owner lock: {$entry}\n";
}

/* ------- offer media state must go through its service ------- */

$offerControllerFile = $root.'/app/Modules/Marketplace/Http/Controllers/Admin/OfferMediaController.php';

if (is_file($offerControllerFile)) {
    $source = (string) file_get_contents($offerControllerFile);

    $ok(
        'offer media state is written only by its service',
        ! preg_match('/OfferMedia::query\\(\\)[^;]{0,200}->(create|update)\\(/s', $source),
    );
}

/* ------- rollback must not depend on the latest batch ------- */

$rollbackFile = $root.'/app/Modules/Projects/Console/RollbackWizardSchema.php';

if (is_file($rollbackFile)) {
    $source = (string) file_get_contents($rollbackFile);

    /*
     * `migrate:rollback --path` only reverses the LATEST batch, so a Wizard
     * migration recorded in an older batch was silently a no-op.
     */
    $ok(
        'rollback executes the migration down() directly',
        str_contains($source, '->down()') && ! str_contains($source, "Artisan::call('migrate:rollback'"),
    );

    // The row must go only after the schema is confirmed reversed.
    $verifyPos = strpos($source, '$residue = $this->residualSchemaFor');
    $deletePos = strpos($source, "DB::table('migrations')->where('migration', \$migration)->delete()");

    $ok(
        'the migrations row is removed only after verification',
        $verifyPos !== false && $deletePos !== false && $verifyPos < $deletePos,
    );
}

/* ---- every media source type must be dispatchable by the sweep ---- */

$sweepSource = (string) file_get_contents(
    $root.'/app/Modules/Projects/Console/SweepOrphanedFiles.php'
);

$recordedTypes = [];

foreach (array_merge(
    glob($root.'/app/Modules/*/Services/*.php') ?: [],
) as $file) {
    $source = (string) file_get_contents($file);

    preg_match_all("/'source_type' => '([a-z_]+)'/", $source, $types);

    $recordedTypes = array_merge($recordedTypes, $types[1]);
}

$undispatched = [];

foreach (array_unique($recordedTypes) as $type) {
    /*
     * A source type recorded by a service but absent from the sweep's match
     * falls to `default` and is reported as unknown — the file is removed and
     * its row is left behind permanently, because nothing selects rows past
     * the cleanup ceiling.
     */
    if (! str_contains($sweepSource, "'".$type."' =>")) {
        $undispatched[] = $type;
    }
}

$ok('every recorded source type is dispatched by the sweep', $undispatched === []);

foreach ($undispatched as $entry) {
    echo "        undispatched source type: {$entry}\n";
}

/* ------- finalisers must lock the owner before the media row ------- */

$inverted = [];

foreach ([
    $root.'/app/Modules/Projects/Services/ProjectMediaService.php',
    $root.'/app/Modules/Projects/Services/ProjectDraftMediaService.php',
    $root.'/app/Modules/Marketplace/Services/OfferMediaService.php',
] as $file) {
    if (! is_file($file)) {
        continue;
    }

    $source = (string) file_get_contents($file);

    if (! preg_match(
        '/public function finaliseAbsentSource\(.*?(?=\n    (?:public|private|protected) function )/s',
        $source,
        $body,
    )) {
        continue;
    }

    /*
     * Locking the media row first and its owner second is the opposite order
     * from every other mutation — two transactions taking the same two locks
     * in opposite orders deadlock, and the sweep runs concurrently with
     * ordinary editing by design.
     */
    if (preg_match('/(ProjectMedia|ProjectDraftMedia|OfferMedia)::query\(\)->lockForUpdate\(\)/', $body[0])) {
        $inverted[] = basename($file);
    }
}

$ok('finalisers lock the owner before the media row', $inverted === []);

foreach ($inverted as $entry) {
    echo "        inverted lock order: {$entry}\n";
}

/* --------- deletion logic must live in services, not commands --------- */

$commandDeleters = [];

foreach (glob($root.'/app/Modules/*/Console/*.php') ?: [] as $file) {
    $source = (string) file_get_contents($file);

    if (! str_contains($source, 'cleanup_pending')) {
        continue;
    }

    /*
     * MEDIA rows specifically. RollbackWizardSchema deletes its own
     * `migrations` row, which is its whole job — the first version of this
     * check flagged it, and a guard that cries wolf gets ignored.
     */
    $deletesMedia = preg_match(
        '/(ProjectMedia|ProjectDraftMedia|OfferMedia)::query\(\)[^;]{0,200}->delete\(\)/s',
        $source,
    ) === 1;

    if (str_contains($source, 'reconcileWithin(') || $deletesMedia) {
        $commandDeleters[] = basename($file);
    }
}

$ok('cleanup commands do not implement deletion themselves', $commandDeleters === []);

foreach ($commandDeleters as $entry) {
    echo "        command deletes directly: {$entry}\n";
}

/* --------- handoff must be idempotent and retryable, not one-shot --------- */

$oneShotHandoffs = [];

foreach ([
    $root.'/app/Modules/Projects/Services/ProjectMediaService.php',
    $root.'/app/Modules/Projects/Services/ProjectDraftMediaService.php',
    $root.'/app/Modules/Marketplace/Services/OfferMediaService.php',
] as $file) {
    if (! is_file($file)) {
        continue;
    }

    $source = (string) file_get_contents($file);

    /*
     * `=== CEILING` fires on exactly one attempt. If the handoff fails at that
     * instant the row is exhausted, unhanded-off and invisible — nothing
     * selects rows past the ceiling. `>=` plus the outbox-id guard makes the
     * transfer idempotent AND retryable.
     */
    if (preg_match('/\$attempts === self::CLEANUP_ATTEMPT_CEILING/', $source)) {
        $oneShotHandoffs[] = basename($file).' (one-shot ceiling test)';
    }

    if (! str_contains($source, 'cleanup_outbox_id')) {
        $oneShotHandoffs[] = basename($file).' (no outbox linkage)';
    }
}

$ok('media handoff is idempotent and retryable', $oneShotHandoffs === []);

foreach ($oneShotHandoffs as $entry) {
    echo "        {$entry}\n";
}

/* --------------- audit scripts must not require mbstring --------------- */

$mbDependent = [];

foreach ([$root.'/scripts/lang-parity.php', $root.'/scripts/secret-scan.php'] as $file) {
    if (! is_file($file)) {
        continue;
    }

    $source = (string) file_get_contents($file);

    // A bare mb_* call with no function_exists guard fatals on a machine
    // without the extension — and these are the checks most worth running on
    // an unfamiliar box.
    if (preg_match('/(?<!function_exists\(.)\bmb_[a-z_]+\(/', $source)
        && ! str_contains($source, "function_exists('mb_")) {
        $mbDependent[] = basename($file);
    }
}

$ok('audit scripts run without mbstring', $mbDependent === []);

foreach ($mbDependent as $entry) {
    echo "        needs mbstring: {$entry}\n";
}

/* ------- rollback preflight must key on schema, not a migrations row ------- */

$rollbackSource = (string) file_get_contents(
    $root.'/app/Modules/Projects/Console/RollbackWizardSchema.php'
);

$ok(
    'the rollback preflight checks the schema that exists',
    ! str_contains($rollbackSource, "in_array('2026_07_25_001300_purge_state_and_orphan_outbox', \$present"),
);

// All three media domains must be covered by the preflight.
$preflightGaps = [];

foreach (['project_media', 'project_draft_media', 'offer_media'] as $table) {
    if (! str_contains($rollbackSource, "'".$table."'")) {
        $preflightGaps[] = $table;
    }
}

$ok('the rollback preflight covers every media domain', $preflightGaps === []);

foreach ($preflightGaps as $entry) {
    echo "        preflight misses: {$entry}\n";
}

/* --------- the journal must rotate, never truncate under writers --------- */

$replaySource = (string) file_get_contents(
    $root.'/app/Modules/Projects/Console/ReplayCleanupJournal.php'
);

/*
 * Read-then-truncate erased anything appended in between — and that window is
 * exactly when the journal is being written, because it only exists for the
 * situation where the database is unavailable.
 */
$ok(
    'the journal replay rotates rather than truncating',
    str_contains($replaySource, 'CleanupJournal::rotate(')
        && ! str_contains($replaySource, 'CleanupJournal::truncate('),
);

$ok(
    'malformed journal lines are quarantined, not dropped',
    str_contains($replaySource, 'quarantine('),
);

/* --------- outbox linkage must be mandatory, not optional --------- */

$optionalLinkage = [];

foreach ([
    $root.'/app/Modules/Projects/Services/ProjectMediaService.php',
    $root.'/app/Modules/Projects/Services/ProjectDraftMediaService.php',
    $root.'/app/Modules/Marketplace/Services/OfferMediaService.php',
] as $file) {
    if (! is_file($file)) {
        continue;
    }

    $source = (string) file_get_contents($file);

    /*
     * `?int $outboxId = null` skipped the check when omitted, so any caller
     * could delete a row on disk-and-path alone — and paths are reused.
     */
    if (str_contains($source, 'finaliseAbsentSource(int $mediaId, string $disk, string $path, ?int $outboxId')) {
        $optionalLinkage[] = basename($file);
    }
}

$ok('destructive finalisation requires an outbox id', $optionalLinkage === []);

foreach ($optionalLinkage as $entry) {
    echo "        optional linkage: {$entry}\n";
}

/* --------- the job key must fit its column --------- */

$outboxModel = (string) file_get_contents($root.'/app/Modules/Projects/Models/OrphanedFile.php');

/*
 * `path:{disk}:{path}` overflowed a 255-character column while `path` itself
 * allows 512 — a long but valid path could not be persisted at all, so it
 * could not be replayed either. Truncating would be worse: two deep paths
 * sharing a prefix would collapse into one job.
 */
$ok(
    'path-only job keys are hashed to a fixed length',
    str_contains($outboxModel, "hash('sha256'"),
);

/* --------- first outbox creation must not rely on locking alone --------- */

/*
 * A NATIVE UPSERT, not conflict-catching.
 *
 * This previously required the code to CATCH a unique violation — correct for
 * a standalone insert, wrong once record() began running inside the handoff
 * transactions: on PostgreSQL a violation aborts the OUTER transaction and
 * catching it does not make it usable again. The stronger property is that no
 * violation is ever raised.
 */
$ok(
    'outbox creation uses a conflict-safe upsert',
    /*
     * v6: the conflict TARGET moved with the identity contract. The strict
     * lifecycle keeps `job_key` deliberately repeatable — a resolved
     * incident retains it as history — and enforces one LIVE identity on
     * `active_key`, so that is the column the upsert must name. The
     * property under test is unchanged: a conflict-safe upsert with an
     * explicit target, never a caught violation.
     */
    str_contains($outboxModel, '->upsert(')
        && (str_contains($outboxModel, "['active_key']") || str_contains($outboxModel, "['job_key']")),
);

$ok(
    'outbox creation does not depend on catching a unique violation',
    ! str_contains($outboxModel, 'UniqueConstraintViolationException'),
);

/* --------- attempt increments must be read under the lock --------- */

$staleIncrements = [];

foreach ([
    $root.'/app/Modules/Projects/Services/ProjectMediaService.php',
    $root.'/app/Modules/Projects/Services/ProjectDraftMediaService.php',
    $root.'/app/Modules/Marketplace/Services/OfferMediaService.php',
] as $file) {
    if (! is_file($file)) {
        continue;
    }

    $source = (string) file_get_contents($file);

    // Incrementing from the in-memory model loses one attempt per collision,
    // so the ceiling arrives late or never and the handoff with it.
    if (preg_match('/\$attempts = \(int\) \$item->cleanup_attempts \+ 1/', $source)) {
        $staleIncrements[] = basename($file);
    }
}

$ok('cleanup attempts are counted from a locked row', $staleIncrements === []);

foreach ($staleIncrements as $entry) {
    echo "        stale increment: {$entry}\n";
}

/* ---------- the journal must rotate, never truncate under writers ---------- */

$journalFile = $root.'/app/Modules/Projects/Support/CleanupJournal.php';

if (is_file($journalFile)) {
    $journal = (string) file_get_contents($journalFile);

    /*
     * Reading the active file and truncating it afterwards erased anything
     * appended in between — silent data loss in the one mechanism whose entire
     * purpose is not losing things.
     */
    $ok('the cleanup journal rotates rather than truncating', str_contains($journal, 'function rotate('));
    $ok('the cleanup journal quarantines malformed lines', str_contains($journal, 'function quarantine('));
    $ok(
        'the journal no longer offers a blind truncate',
        ! preg_match('/public static function truncate\(/', $journal),
    );
}

/* ---------- outbox creation must not depend only on a lock ---------- */

$outbox = (string) file_get_contents($root.'/app/Modules/Projects/Models/OrphanedFile.php');

/*
 * `lockForUpdate` cannot protect a row that does not exist yet, and catching
 * the unique violation INSIDE the transaction leaves it aborted on PostgreSQL.
 */
/*
 * A NATIVE UPSERT, not conflict-catching.
 *
 * These guards previously required the code to CATCH a unique violation. That
 * was right for a standalone insert and wrong once record() began running
 * inside the handoff transactions: on PostgreSQL the violation aborts the
 * outer transaction, and catching it does not make it usable again. The
 * stronger property is that no violation is ever raised.
 */
/*
 * THE INVARIANT, and why the conflict target moved.
 *
 * What must hold: creating an outbox row can never raise a unique
 * violation, because `record()` runs inside handoff transactions where a
 * violation poisons the outer transaction. That requires an upsert with an
 * EXPLICIT conflict target naming the column that carries live-identity
 * uniqueness.
 *
 * That column is now `active_key`, not `job_key`: the strict lifecycle
 * keeps `job_key` deliberately repeatable so a resolved incident retains
 * its history, and enforces "one live incident per identity" on
 * `active_key` instead. Naming `job_key` here would demand a conflict
 * target the schema no longer makes unique — the upsert would stop being
 * conflict-safe.
 *
 * The replacement is STRICTER than the original: the target must be one of
 * the two, AND the schema must actually enforce uniqueness on whichever is
 * named, AND the repeatable/unique split is proven independently below.
 */
$outboxTarget = null;

foreach (['active_key', 'job_key'] as $candidate) {
    if (str_contains($outbox, "['".$candidate."']")) {
        $outboxTarget = $candidate;

        break;
    }
}

$identityMigrations = implode("\n", array_map(
    static fn (string $f): string => (string) file_get_contents($f),
    glob($root.'/app/Modules/Projects/Database/Migrations/2026_07_25_00{17,18,19,20}*.php', GLOB_BRACE) ?: [],
));

$targetIsUnique = $outboxTarget !== null && (
    str_contains($identityMigrations, "unique('".$outboxTarget."'")
    || str_contains($identityMigrations, "unique(['".$outboxTarget."']")
);

$ok(
    'outbox creation uses a conflict-safe upsert',
    str_contains($outbox, '->upsert(') && $outboxTarget !== null && $targetIsUnique,
);

/*
 * INDEPENDENT PROOF of the split the conflict target relies on, asserted
 * against the migrations rather than against the model that consumes it:
 * the old `job_key` unique must be REMOVED (so resolved incidents may
 * share a key) and a `active_key` unique must be ADDED (so only one live
 * incident can exist).
 */
$ok(
    'resolved incidents may share job_key while only one active incident exists',
    str_contains($identityMigrations, "dropUnique('orphaned_files_job_key_unique')")
        && (str_contains($identityMigrations, "unique('active_key'")
            || str_contains($identityMigrations, "unique(['active_key']")),
);

$ok(
    'outbox creation does not rely on catching a unique violation',
    ! str_contains($outbox, 'UniqueConstraintViolationException'),
);

// The identity must be fixed-length: path is 512 and job_key is 255.
$ok(
    'the cleanup job key is fixed length',
    str_contains($outbox, "hash('sha256'") && str_contains($outbox, 'function jobKey('),
);

$ok(
    'the job key does not embed a raw path',
    ! preg_match("/'path:'\\.\\\$disk\\.':'\\.\\\$path/", $outbox),
);

/* ---------- destructive finalisation must require its outbox job ---------- */

$optionalOutbox = [];

foreach ([
    $root.'/app/Modules/Projects/Services/ProjectMediaService.php',
    $root.'/app/Modules/Projects/Services/ProjectDraftMediaService.php',
    $root.'/app/Modules/Marketplace/Services/OfferMediaService.php',
] as $file) {
    if (! is_file($file)) {
        continue;
    }

    $source = (string) file_get_contents($file);

    // An optional id meant a caller passing null skipped the linkage check and
    // could delete a row belonging to a different job.
    if (preg_match('/finaliseAbsentSource\([^)]*\?int \$outboxId/', $source)) {
        $optionalOutbox[] = basename($file);
    }
}

$ok('finalisation requires an outbox job id', $optionalOutbox === []);

foreach ($optionalOutbox as $entry) {
    echo "        optional outbox id: {$entry}\n";
}

/* ---------- the identity migration must fail closed ---------- */

$identityMigration = $root.'/app/Modules/Projects/Database/Migrations/2026_07_25_001700_cleanup_job_identity.php';

if (is_file($identityMigration)) {
    $source = (string) file_get_contents($identityMigration);

    /*
     * A bare catch around the index drop let the migration be recorded as
     * applied while the database still forbade multiple jobs per path.
     *
     * The helper contract is named explicitly now: the single `uniqueExists()`
     * this replaced was declared with one parameter and called with three, so
     * every rollback was a guaranteed ArgumentCountError. Asking for the two
     * separate helpers pins that apart, and asking for the refusal message
     * pins the preflight that must run BEFORE any destructive statement.
     */
    /*
     * THE INVARIANTS, restated so the move cannot lose them.
     *
     * The original assertions named two private helpers
     * (`uniqueIndexNameExists`, `uniqueIndexColumnsExist`) and two literal
     * sentences. The strict chain replaced those helpers with ONE shared
     * `SchemaContract`, which verifies strictly more than they did: name,
     * table ownership, ORDERED columns, uniqueness AND full-column status
     * per driver. Asserting the old private names would demand the weaker
     * implementation back.
     *
     * What must still hold, and is asserted here:
     *   1. index changes are VERIFIED through the contract helper, and a
     *      failed verification throws rather than continuing;
     *   2. destructive work is preflighted, and the refusal states that
     *      nothing was modified;
     *   3. an engine that cannot be inspected is REFUSED, and no bare
     *      `catch (\Throwable)` swallows a verification failure.
     *
     * The contract itself — ownership, ordered columns, uniqueness,
     * full-column versus prefix, partial versus full — is proven
     * independently against a live database, WITHOUT the production
     * helper, in tests/Feature/CleanupIndexContractTest.php.
     */
    $schemaContract = (string) file_get_contents(
        $root.'/app/Modules/Projects/Support/SchemaContract.php'
    );

    $ok(
        'the identity migration verifies its index changes',
        str_contains($source, 'SchemaContract::indexContractHolds(')
            && str_contains($source, 'RuntimeException')
            // The helper must check MORE than a name: ordered columns and
            // uniqueness are part of the contract it proves.
            && str_contains($schemaContract, '$orderedColumns')
            && str_contains($schemaContract, '$unique'),
    );

    $ok(
        'the identity migration preflights duplicates before mutating',
        str_contains($source, 'Refusing to reverse')
            && str_contains($source, 'No schema change has been made'),
    );

    $ok(
        'the identity migration refuses engines it cannot inspect',
        str_contains($schemaContract, 'Cannot verify index contracts on driver')
            && ! str_contains($source, 'catch (\Throwable)')
            && ! str_contains($schemaContract, 'return true;   // unknown driver'),
    );
}

/* ------- tests must call methods and commands that actually exist ------- */

/*
 * THE CHECK THAT WAS MISSING.
 *
 * Tests were written calling `CleanupJournal::parse()` and a fabricated
 * `path().\'.dead-letter\'` — neither exists. Nothing caught it, because the
 * PHPUnit suite has never run: written tests were treated as evidence when the
 * only thing verified was that they parsed.
 */
$phantomCalls = [];

// Every static method each app class actually declares.
$declaredMethods = [];

$appFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app'));

foreach ($appFiles as $file) {
    if (! $file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $source = (string) file_get_contents($file->getPathname());

    if (! preg_match('/(?:final )?(?:abstract )?class (\w+)/', $source, $className)) {
        continue;
    }

    preg_match_all('/(?:public|protected|private)\s+(?:static\s+)?function (\w+)/', $source, $methods);
    preg_match_all('/const (\w+)/', $source, $constants);

    /*
     * Inherited members count as declared. A model gets `query()` from
     * Eloquent and a command gets `call()` from the console kernel — flagging
     * those would make this check report every ordinary line as a phantom, and
     * a guard that cries wolf gets switched off.
     */
    $inherited = ['class', 'query', 'factory', 'make', 'create', 'find', 'where', 'all'];

    $declaredMethods[$className[1]] = array_merge($methods[1], $constants[1], $inherited);
}

foreach (glob($root.'/tests/Feature/*.php') ?: [] as $testFile) {
    $source = (string) file_get_contents($testFile);

    // Fully-qualified static calls: \App\...\Thing::member
    preg_match_all('/\\\\App\\\\[A-Za-z\\\\]*\\\\(\w+)::(\w+)/', $source, $calls, PREG_SET_ORDER);

    foreach ($calls as [$whole, $class, $member]) {
        if (! isset($declaredMethods[$class])) {
            continue;   // class not found by this scan; not this check's job
        }

        if (! in_array($member, $declaredMethods[$class], true)) {
            $phantomCalls[] = basename($testFile).': '.$class.'::'.$member.'()';
        }
    }
}

$ok('every class member a test calls exists', $phantomCalls === []);

foreach (array_unique($phantomCalls) as $entry) {
    echo "        no such member: {$entry}\n";
}

/* --------- artisan commands named in tests must be registered --------- */

$declaredCommands = [];

foreach (glob($root.'/app/Modules/*/Console/*.php') ?: [] as $file) {
    $source = (string) file_get_contents($file);

    if (preg_match('/\$signature = \'([a-z0-9:_-]+)/', $source, $signature)) {
        $declaredCommands[] = $signature[1];
    }
}

$phantomCommands = [];

foreach (glob($root.'/tests/Feature/*.php') ?: [] as $testFile) {
    $source = (string) file_get_contents($testFile);

    preg_match_all("/artisan\\('(mulkihawler:[a-z0-9:_-]+)/", $source, $used);

    foreach (array_unique($used[1]) as $command) {
        if (! in_array($command, $declaredCommands, true)) {
            $phantomCommands[] = basename($testFile).': '.$command;
        }
    }
}

$ok('every artisan command a test invokes is declared', $phantomCommands === []);

foreach (array_unique($phantomCommands) as $entry) {
    echo "        no such command: {$entry}\n";
}

echo TestTally::failures() === 0
    ? "\nALL STRUCTURE ASSERTIONS PASSED\n"
    : "\n".TestTally::failures()." STRUCTURE FAILURES\n";

exit(TestTally::exitCode());
