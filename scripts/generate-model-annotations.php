<?php

declare(strict_types=1);

/*
 * Generate `@property` annotations for every Eloquent model from the REAL
 * schema, so `checkModelProperties: true` has something truthful to check.
 *
 * This is a repair, not a suppression: PHPStan was right that the properties
 * were undeclared. The annotations are derived from the migrated database and
 * the model's own casts, so they describe what the code actually returns
 * rather than what somebody hoped it returned.
 *
 * Idempotent: an existing generated block is replaced, never duplicated.
 *
 * Usage: php scripts/generate-model-annotations.php [--check]
 */

require __DIR__.'/../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../bootstrap/app.php';
// String class name: Pint's global_namespace_import rule rewrites a
// leading-backslash constant into an unimported short name here.
$app->make('Illuminate\\Contracts\\Console\\Kernel')->bootstrap();

/*
 * A DISPOSABLE, SELF-MIGRATED DATABASE.
 *
 * The annotations describe the schema, so the schema has to exist — but this
 * script runs as a packaging gate, where depending on whatever database the
 * host happens to have configured would make the gate environment-dependent
 * and the generated output non-deterministic. A temporary SQLite file migrated
 * here answers only to the migrations in this tree.
 */
$scratch = tempnam(sys_get_temp_dir(), 'mulkihawler-annotations-').'.sqlite';
touch($scratch);

register_shutdown_function(static function () use ($scratch): void {
    @unlink($scratch);
});

config([
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $scratch,
    'database.connections.sqlite.foreign_key_constraints' => true,
]);

DB::purge('sqlite');

Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const BEGIN = ' * ---- generated model properties (scripts/generate-model-annotations.php)';
const END = ' * ---- end generated model properties';

$check = in_array('--check', $argv, true);

/** Map a database column type plus any cast onto a PHP type. */
function phpType(string $dbType, ?string $cast, bool $nullable): string
{
    $base = match (true) {
        $cast === null => match (true) {
            str_contains($dbType, 'int') => 'int',
            str_contains($dbType, 'bool') => 'bool',
            str_contains($dbType, 'json') => 'array<string, mixed>',
            str_contains($dbType, 'float'), str_contains($dbType, 'double') => 'float',
            str_contains($dbType, 'decimal'), str_contains($dbType, 'numeric') => 'string',
            str_contains($dbType, 'date'), str_contains($dbType, 'time') => '\Illuminate\Support\Carbon',
            default => 'string',
        },
        $cast === 'boolean', $cast === 'bool' => 'bool',
        $cast === 'integer', $cast === 'int' => 'int',
        $cast === 'float', $cast === 'double' => 'float',
        /*
         * A json/array cast can yield NULL even on a NOT NULL column: Laravel
         * runs the value through `json_decode`, and invalid JSON — a legacy row,
         * a truncated write, a manual edit — decodes to null. Declaring these
         * non-null made every defensive `is_array()` look redundant, which is
         * how a guard against corrupt data gets deleted.
         */
        $cast === 'array', $cast === 'json', $cast === 'collection' => 'array<string, mixed>|null',
        $cast === 'datetime', $cast === 'date', $cast === 'immutable_datetime',
        $cast === 'timestamp', str_starts_with($cast, 'datetime:'), str_starts_with($cast, 'date:') => '\Illuminate\Support\Carbon',
        str_starts_with($cast, 'decimal:') => 'string',
        str_starts_with($cast, 'encrypted') => 'string',
        enum_exists($cast) => '\\'.ltrim($cast, '\\'),
        class_exists($cast) => 'mixed',
        default => 'mixed',
    };

    if (str_ends_with($base, '|null') || $base === 'mixed') {
        return $base;
    }

    return $nullable ? $base.'|null' : $base;
}

$files = [];

foreach (['app/Modules', 'app/Models'] as $root) {
    $dir = __DIR__.'/../'.$root;

    if (! is_dir($dir)) {
        continue;
    }

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php' && str_contains($file->getPathname(), '/Models/')) {
            $files[] = $file->getPathname();
        }
    }
}

sort($files);

$written = 0;
$stale = [];

foreach ($files as $path) {
    $source = file_get_contents($path);

    if (! preg_match('/^namespace\s+([^;]+);/m', $source, $ns)
        || ! preg_match('/^(?:final\s+)?(?:abstract\s+)?class\s+(\w+)/m', $source, $cls)) {
        continue;
    }

    $fqcn = $ns[1].'\\'.$cls[1];

    if (! class_exists($fqcn) || ! is_subclass_of($fqcn, Model::class)) {
        continue;
    }

    /** @var Model $model */
    $model = new $fqcn;
    $table = $model->getTable();

    if (! Schema::hasTable($table)) {
        continue;
    }

    $casts = $model->getCasts();
    $lines = [];

    foreach (Schema::getColumns($table) as $column) {
        $name = (string) $column['name'];
        $type = phpType(
            strtolower((string) ($column['type_name'] ?? $column['type'] ?? 'string')),
            isset($casts[$name]) ? (string) $casts[$name] : null,
            (bool) ($column['nullable'] ?? true),
        );

        $lines[] = ' * @property '.$type.' $'.$name;
    }

    if ($lines === []) {
        continue;
    }

    $block = BEGIN."\n *\n".implode("\n", $lines)."\n *\n".END;

    /*
     * Expand a SINGLE-LINE class docblock first.
     *
     * The insertion pattern below only recognises a multi-line docblock, so a
     * class documented as `/** The rendered advertisement. *\/` fell through to
     * the "no docblock" branch and had a SECOND block prepended — two stacked
     * comments where only the last is read. Normalising the shape first means
     * the existing prose is merged rather than shadowed.
     */
    $source = (string) preg_replace_callback(
        '/^\/\*\* (.+?) \*\/\n((?:final\s+)?(?:abstract\s+)?class\s+'.preg_quote($cls[1], '/').'\b)/m',
        static fn (array $m): string => "/**\n * ".$m[1]."\n */\n".$m[2],
        $source,
    );

    // ...and merge one that already sits in front of a generated block.
    $source = (string) preg_replace(
        '/^\/\*\* (.+?) \*\/\n\/\*\*\n/m',
        "/**\n * $1\n *\n",
        $source,
    );

    // Replace an existing generated block, or insert one into the class docblock.
    if (str_contains($source, BEGIN)) {
        $updated = preg_replace(
            '/'.preg_quote(BEGIN, '/').'.*?'.preg_quote(END, '/').'/s',
            $block,
            $source,
        );
    } else {
        $pattern = '/(\n)(\/\*\*(?:(?!\*\/).)*?\n)(\s*\*\/\n(?:final\s+)?(?:abstract\s+)?class\s+'.preg_quote($cls[1], '/').'\b)/s';

        if (preg_match($pattern, $source)) {
            $updated = preg_replace($pattern, '$1$2 *'."\n".$block."\n".'$3', $source, 1);
        } else {
            // No class docblock: add one.
            $updated = preg_replace(
                '/^((?:final\s+)?(?:abstract\s+)?class\s+'.preg_quote($cls[1], '/').'\b)/m',
                "/**\n".$block."\n */\n".'$1',
                $source,
                1,
            );
        }
    }

    if ($updated === null || $updated === $source) {
        continue;
    }

    if ($check) {
        /*
         * COMPARE TYPE IDENTITY, RESOLVING IMPORTS.
         *
         * This generator emits fully-qualified docblock types
         * (`\Illuminate\Support\Carbon`), and Pint then rewrites them to the
         * imported short name (`Carbon`) and adds a `use` statement. Comparing
         * the two forms literally reported every model as stale forever, which
         * is why the gate was permanently red — a formatter/generator identity
         * mismatch, not a schema difference.
         *
         * Both sides are therefore resolved through the file's own `use` map
         * before comparison. Name, nullability, array generics and cast
         * interpretation all still have to agree exactly; only the spelling of
         * a class name is allowed to differ.
         */
        $useMap = [];

        if (preg_match_all('/^use\s+([^;]+);/m', $source, $uses)) {
            foreach ($uses[1] as $import) {
                $import = trim($import);

                if (str_contains($import, ' as ')) {
                    [$fqcn, $alias] = array_map('trim', explode(' as ', $import, 2));
                } else {
                    $fqcn = $import;
                    $alias = substr((string) strrchr('\\'.$import, '\\'), 1);
                }

                $useMap[$alias] = '\\'.ltrim($fqcn, '\\');
            }
        }

        $resolve = static function (string $type) use ($useMap): string {
            $parts = array_map(static function (string $part) use ($useMap): string {
                $nullable = str_ends_with($part, '|null');
                $bare = $part;

                /*
                 * Primitive spellings first: PHP and Laravel both accept more
                 * than one name for the same type, and a gate that called
                 * `int` and `integer` different types would fail on a
                 * difference that does not exist.
                 */
                $primitives = [
                    'integer' => 'int',
                    'boolean' => 'bool',
                    'double' => 'float',
                    'real' => 'float',
                ];

                if (isset($primitives[strtolower($bare)])) {
                    return $primitives[strtolower($bare)];
                }

                /*
                 * Only unqualified class names are aliased. The pattern needs a
                 * DOUBLE backslash to mean "optional leading backslash" — with a
                 * single one it read as an optional literal `?`, matched
                 * nothing, and every imported `Carbon` stayed unresolved. That
                 * single character is why all 55 models reported stale.
                 */
                if (! preg_match('/^\\\\?([A-Z]\\w*)$/', $bare, $m)) {
                    return $part;
                }

                $resolved = $useMap[$m[1]] ?? ('\\'.$m[1]);

                return $nullable ? $resolved.'|null' : $resolved;
            }, explode('|', $type));

            return implode('|', $parts);
        };

        $normalise = static function (string $text) use ($resolve): array {
            preg_match_all('/@property\s+(\S+)\s+(\$\w+)/', $text, $m, PREG_SET_ORDER);

            $out = array_map(
                static fn (array $x): string => $resolve($x[1]).' '.$x[2],
                $m,
            );
            sort($out);

            return $out;
        };

        if ($normalise($source) !== $normalise($updated)) {
            $stale[] = $path;
        }

        continue;
    }

    file_put_contents($path, $updated);
    $written++;
}

if ($check) {
    if ($stale !== []) {
        fwrite(STDERR, "Model annotations are stale for:\n  ".implode("\n  ", $stale)."\n");
        exit(1);
    }

    echo "model annotations are current\n";
    exit(0);
}

echo "annotated {$written} model(s)\n";
