<?php

declare(strict_types=1);

namespace Mulkihawler\Tooling;

use RuntimeException;

/**
 * Token-aware docblock editing for Eloquent relation and scope generics.
 *
 * Two earlier attempts edited these docblocks with regular expressions and by
 * walking lines backwards. Both corrupted files: the regex matched across an
 * unrelated comment, and the line walk found the closing marker of a DIFFERENT
 * docblock and inserted tags outside any comment, leaving `OrphanedFile.php`
 * unparseable. PHP's own tokeniser knows exactly which comment belongs to which
 * function, so it is used here instead of guessing from text.
 *
 * The engine is transactional: every file is rewritten in memory, syntax
 * checked and semantically validated, and the tree is only touched once ALL
 * proposed files pass. A failure writes nothing at all.
 */
final class RelationGenerics
{
    /** @var array<string, string> Eloquent relation call => relation class */
    public const RELATIONS = [
        'hasMany' => 'HasMany',
        'hasOne' => 'HasOne',
        'belongsTo' => 'BelongsTo',
        'belongsToMany' => 'BelongsToMany',
        'morphMany' => 'MorphMany',
        'morphOne' => 'MorphOne',
        'morphTo' => 'MorphTo',
        'hasManyThrough' => 'HasManyThrough',
        'hasOneThrough' => 'HasOneThrough',
    ];

    /* ================================================================ api */

    /**
     * The source this file SHOULD have, or null when it is already correct.
     */
    public function propose(string $source): ?string
    {
        $class = $this->className($source);

        if ($class === null) {
            return null;
        }

        $updated = $source;

        /*
         * ONLY TOUCH WHAT IS ACTUALLY WRONG.
         *
         * This generator emits fully-qualified types; Pint then rewrites them
         * to the imported short name. Regenerating unconditionally meant the
         * two took turns rewriting the same line forever, so the gate could
         * never be idempotent. The semantic check already knows an alias and
         * its FQCN are the same type — anything it accepts is left alone.
         */
        $broken = [];

        foreach ($this->problems($source, '') as $problem) {
            if (preg_match('/: (\w+)\(\)/', $problem, $m)) {
                $broken[$m[1]] = true;
            }
        }

        foreach ($this->relationMethods($source) as $method => $spec) {
            if (! isset($broken[$method])) {
                continue;
            }

            $generic = $spec['type'] === 'MorphTo'
                ? 'MorphTo<\Illuminate\Database\Eloquent\Model, $this>'
                : $spec['type'].'<'.$spec['related'].', $this>';

            $updated = $this->upsertTags($updated, $method, ['@return' => $generic]);
        }

        foreach ($this->scopeMethods($updated) as $method) {
            if (! isset($broken[$method])) {
                continue;
            }

            $updated = $this->upsertTags($updated, $method, [
                '@param' => 'Builder<'.$class.'>  $query',
                '@return' => 'Builder<'.$class.'>',
            ]);
        }

        return $updated === $source ? null : $updated;
    }

    /**
     * Semantic problems in a file: what the docblocks claim versus what the
     * code does. Formatting and import style are deliberately not compared.
     *
     * @return list<string>
     */
    public function problems(string $source, string $label): array
    {
        $class = $this->className($source);

        if ($class === null) {
            return [];
        }

        $resolve = $this->resolver($source);
        $problems = [];

        foreach ($this->relationMethods($source) as $method => $spec) {
            $doc = $this->docblockFor($source, $method);
            $type = $spec['type'];

            if ($doc === null || ! preg_match('/@return\s+'.$type.'<([^,]+),\s*([^>]+)>/', $doc, $m)) {
                $problems[] = "{$label}: {$method}() has no {$type} generics";

                continue;
            }

            $expected = $type === 'MorphTo'
                ? ['\Illuminate\Database\Eloquent\Model', '$this']
                : [$resolve($spec['related']), '$this'];

            $actual = [$resolve($m[1]), $resolve($m[2])];

            if ($actual !== $expected) {
                $problems[] = sprintf(
                    '%s: %s() declares %s<%s, %s> but the code returns %s<%s, %s>',
                    $label, $method, $type, $actual[0], $actual[1], $type, $expected[0], $expected[1],
                );
            }
        }

        foreach ($this->scopeMethods($source) as $method) {
            $doc = $this->docblockFor($source, $method);

            if ($doc === null || ! preg_match('/@param\s+Builder<([^>]+)>/', $doc, $m)) {
                $problems[] = "{$label}: {$method}() has no Builder generic";

                continue;
            }

            if ($resolve($m[1]) !== $resolve($class)) {
                $problems[] = "{$label}: {$method}() names {$m[1]}, expected {$class}";
            }
        }

        return $problems;
    }

    /* ========================================================== discovery */

    public function className(string $source): ?string
    {
        return preg_match('/^(?:final\s+)?(?:abstract\s+)?class\s+(\w+)/m', $source, $m) ? $m[1] : null;
    }

    /**
     * Relation methods and the model each one relates to, read from the call.
     *
     * @return array<string, array{type: string, related: string}>
     */
    public function relationMethods(string $source): array
    {
        $found = [];

        foreach (self::RELATIONS as $call => $type) {
            /*
             * `morphTo()` takes NO class argument — the related model is
             * decided at runtime by the stored morph type — so requiring
             * `::class` here silently skipped every polymorphic relation and
             * left it unannotated.
             */
            $pattern = $type === 'MorphTo'
                ? '/public function (\w+)\(\)\s*:\s*MorphTo\s*\{\s*return \$this->morphTo\(/s'
                : '/public function (\w+)\(\)\s*:\s*'.$type
                    .'\s*\{\s*return \$this->'.$call.'\(\s*([\w\\\\]+)::class/s';

            if (! preg_match_all($pattern, $source, $matches, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as $m) {
                $found[$m[1]] = ['type' => $type, 'related' => $m[2] ?? 'Model'];
            }
        }

        return $found;
    }

    /** @return list<string> */
    public function scopeMethods(string $source): array
    {
        preg_match_all('/public function (scope\w+)\(Builder \$query/', $source, $m);

        return $m[1];
    }

    /**
     * Resolve a written type name to a fully qualified one.
     *
     * `self`, `static` and `$this` all mean the declaring side, so they are
     * normalised together; an imported alias and its FQCN must compare equal.
     */
    public function resolver(string $source): callable
    {
        $namespace = preg_match('/^namespace\s+([^;]+);/m', $source, $n) ? trim($n[1]) : '';
        $uses = [];

        if (preg_match_all('/^use\s+([^;]+);/m', $source, $u)) {
            foreach ($u[1] as $import) {
                $import = trim($import);

                if (str_contains($import, ' as ')) {
                    [$fqcn, $alias] = array_map('trim', explode(' as ', $import, 2));
                } else {
                    $fqcn = $import;
                    $alias = substr((string) strrchr('\\'.$import, '\\'), 1);
                }

                $uses[$alias] = '\\'.ltrim($fqcn, '\\');
            }
        }

        return static function (string $name) use ($uses, $namespace): string {
            $name = trim($name);

            if (in_array($name, ['$this', 'self', 'static'], true)) {
                return '$this';
            }

            if (str_starts_with($name, '\\')) {
                return $name;
            }

            if (isset($uses[$name])) {
                return $uses[$name];
            }

            return $namespace === '' ? '\\'.$name : '\\'.$namespace.'\\'.$name;
        };
    }

    /* ====================================================== token editing */

    /**
     * The docblock belonging to a method, or null when it has none.
     *
     * "Belonging" is decided by the tokeniser: only whitespace, visibility and
     * static/final/abstract modifiers, and attributes may sit between the
     * comment and the `function` keyword. An unrelated comment further up the
     * file is NOT this method's docblock, which is exactly what the previous
     * line-walking implementation got wrong.
     */
    public function docblockFor(string $source, string $method): ?string
    {
        $tokens = token_get_all($source);
        $index = $this->functionTokenIndex($tokens, $method);

        if ($index === null) {
            return null;
        }

        $doc = $this->docblockIndex($tokens, $index);

        return $doc === null ? null : $tokens[$doc][1];
    }

    /**
     * Insert or update tags on a method's docblock, preserving everything else.
     *
     * @param  array<string, string>  $tags  tag name => value
     */
    public function upsertTags(string $source, string $method, array $tags): string
    {
        $tokens = token_get_all($source);
        $index = $this->functionTokenIndex($tokens, $method);

        if ($index === null) {
            return $source;
        }

        $indent = $this->indentFor($tokens, $index);
        $docIndexes = $this->docblockIndexes($tokens, $index);

        if ($docIndexes !== []) {
            // Merge every attached docblock, oldest first, so prose written in
            // the first survives and the generics in the last win.
            $existing = implode("\n", array_map(
                static fn (int $i): string => $tokens[$i][1],
                $docIndexes,
            ));

            $block = $this->buildDocblock($existing, $tags, $indent);
            $keep = $docIndexes[count($docIndexes) - 1];

            if (count($docIndexes) === 1 && $tokens[$keep][1] === $block) {
                return $source;
            }

            foreach ($docIndexes as $i) {
                if ($i === $keep) {
                    $tokens[$i][1] = $block;

                    continue;
                }

                // Drop the superseded block and the blank line after it.
                $tokens[$i][1] = '';

                if (isset($tokens[$i + 1]) && is_array($tokens[$i + 1]) && $tokens[$i + 1][0] === T_WHITESPACE) {
                    $tokens[$i + 1][1] = '';
                }
            }

            return $this->render($tokens);
        }

        $block = $this->buildDocblock(null, $tags, $indent);

        // No docblock: insert one before the first modifier of this method.
        $insertAt = $this->declarationStart($tokens, $index);
        $out = '';

        foreach ($tokens as $i => $token) {
            if ($i === $insertAt) {
                $out .= $block."\n".$indent;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /**
     * Compose a docblock, keeping prose and every tag we are not replacing.
     *
     * @param  array<string, string>  $tags
     */
    public function buildDocblock(?string $existing, array $tags, string $indent): string
    {
        $prose = [];
        $keptTags = [];

        if ($existing !== null) {
            foreach (explode("\n", $existing) as $raw) {
                $line = trim($raw);
                $line = preg_replace('#^/\*\*#', '', (string) $line) ?? '';
                $line = preg_replace('#\*/$#', '', $line) ?? '';
                $line = ltrim(trim($line), '*');
                $line = ltrim($line, ' ');

                if ($line === '' && $prose === []) {
                    continue;
                }

                if (str_starts_with($line, '@')) {
                    // Replaced tags are dropped; every other tag is preserved,
                    // including @throws, @template, @deprecated and @see.
                    $name = strtok($line, ' ') ?: $line;
                    $isParamQuery = $name === '@param' && str_contains($line, '$query');
                    $replaced = isset($tags[$name]) && ($name !== '@param' || $isParamQuery);

                    if (! $replaced) {
                        $keptTags[] = $line;
                    }

                    continue;
                }

                $prose[] = $line;
            }
        }

        while ($prose !== [] && trim((string) end($prose)) === '') {
            array_pop($prose);
        }

        $newTags = [];

        foreach ($tags as $name => $value) {
            $newTags[] = $name === '@param' ? "{$name}  {$value}" : "{$name} {$value}";
        }

        $body = $prose;

        if ($body !== [] && ($keptTags !== [] || $newTags !== [])) {
            $body[] = '';
        }

        $body = array_merge($body, $keptTags, $newTags);

        /*
         * The FIRST line carries no indent.
         *
         * A docblock token is replaced in place, and the whitespace token
         * before it already ends with the declaration's indentation — so
         * prefixing here produced eight spaces where four belong, and an
         * already-correct docblock was rewritten on every run. The insertion
         * path adds the leading indent itself, where there is no preceding
         * comment to inherit it from.
         */
        $lines = ['/**'];

        foreach ($body as $line) {
            $lines[] = rtrim($indent.' * '.$line);
        }

        $lines[] = $indent.' */';

        return implode("\n", $lines);
    }

    /* ------------------------------------------------------------ tokens */

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function functionTokenIndex(array $tokens, string $method): ?int
    {
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }

            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    continue;
                }

                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING && $tokens[$j][1] === $method) {
                    return $i;
                }

                break;
            }
        }

        return null;
    }

    /**
     * The index of this declaration's first token (its earliest modifier).
     *
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     */
    private function declarationStart(array $tokens, int $functionIndex): int
    {
        $modifiers = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT];
        $start = $functionIndex;

        for ($i = $functionIndex - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }

            if (is_array($token) && in_array($token[0], $modifiers, true)) {
                $start = $i;

                continue;
            }

            break;
        }

        return $start;
    }

    /**
     * The index of the docblock attached to this declaration, if any.
     *
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     */
    private function docblockIndex(array $tokens, int $functionIndex): ?int
    {
        $all = $this->docblockIndexes($tokens, $functionIndex);

        return $all === [] ? null : $all[count($all) - 1];
    }

    /**
     * Every docblock attached to this declaration.
     *
     * Normally one. An earlier generator prepended a second in front of methods
     * that already had one, leaving two stacked comments where only the last is
     * read by any tool — so all of them are collected and merged rather than
     * one being silently ignored.
     *
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     * @return list<int>
     */
    private function docblockIndexes(array $tokens, int $functionIndex): array
    {
        $modifiers = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT];
        $depth = 0;
        $found = [];

        for ($i = $functionIndex - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            // Step back over a whole attribute group: #[Foo(...)]
            if ($token === ']') {
                $depth++;

                continue;
            }

            if (is_array($token) && defined('T_ATTRIBUTE') && $token[0] === T_ATTRIBUTE) {
                $depth--;

                continue;
            }

            if ($depth > 0) {
                continue;
            }

            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }

            if (is_array($token) && in_array($token[0], $modifiers, true)) {
                continue;
            }

            if (is_array($token) && $token[0] === T_DOC_COMMENT) {
                $found[] = $i;

                continue;
            }

            // Anything else — a property, another function's brace, a plain
            // comment — means this declaration has no further docblock.
            break;
        }

        sort($found);

        return $found;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function indentFor(array $tokens, int $functionIndex): string
    {
        $start = $this->declarationStart($tokens, $functionIndex);

        for ($i = $start - 1; $i >= 0; $i--) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                $parts = explode("\n", $tokens[$i][1]);

                return end($parts);
            }

            break;
        }

        return '    ';
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function render(array $tokens): string
    {
        $out = '';

        foreach ($tokens as $token) {
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /* ==================================================== transactionality */

    /**
     * Syntax-check a proposed file body without touching the tree.
     */
    public function isParseable(string $source, ?string &$error = null): bool
    {
        $tmp = tempnam(sys_get_temp_dir(), 'relgen-').'.php';
        file_put_contents($tmp, $source);

        $output = [];
        $status = 0;
        exec(escapeshellcmd(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1', $output, $status);

        @unlink($tmp);

        if ($status !== 0) {
            $error = implode("\n", $output);

            return false;
        }

        return true;
    }

    /**
     * Apply proposals to disk, or nothing at all.
     *
     * @param  array<string, string>  $proposals  path => new source
     * @return list<string> the paths written
     */
    public function commit(array $proposals): array
    {
        foreach ($proposals as $path => $source) {
            $error = null;

            if (! $this->isParseable($source, $error)) {
                throw new RuntimeException("Generated output for {$path} does not parse:\n{$error}");
            }

            $problems = $this->problems($source, $path);

            if ($problems !== []) {
                throw new RuntimeException(
                    "Generated output for {$path} fails semantic validation:\n  ".implode("\n  ", $problems)
                );
            }
        }

        $written = [];

        foreach ($proposals as $path => $source) {
            file_put_contents($path, $source);
            $written[] = $path;
        }

        return $written;
    }
}
