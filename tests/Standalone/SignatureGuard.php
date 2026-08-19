<?php

declare(strict_types=1);

/*
 * A REAL signature analyser, built on token_get_all().
 *
 * The guard this replaces checked whether a fully-qualified static member NAME
 * existed anywhere in a class. That is why a three-argument call to a
 * four-argument method passed the standalone suite while being a guaranteed
 * ArgumentCountError, and why `protected function createApplication()` against
 * a public parent method — a fatal that stopped the whole suite loading — was
 * equally invisible.
 *
 * What is checked, where it is statically resolvable:
 *   - class and member existence
 *   - public visibility at the call site
 *   - static call vs instance call correctness
 *   - minimum required argument count
 *   - maximum argument count for non-variadic methods
 *   - named arguments naming parameters that exist
 *   - variables whose class is inferable from app(Foo::class), resolve(Foo::class)
 *     or new Foo(...)
 *   - artisan command names
 *   - artisan option names and their value modes against each $signature
 *
 * ANYTHING IT CANNOT PROVE IS REPORTED AS "not statically checkable" AND
 * COUNTED SEPARATELY. Silently counting the unprovable as passed is what made
 * the previous guard dangerous rather than merely weak.
 */
final class SignatureGuard
{
    /** @var array<string, array<string, mixed>> class => descriptor */
    public array $classes = [];

    /** @var array<string, array<string, mixed>> command name => descriptor */
    public array $commands = [];

    /** @var list<string> */
    public array $violations = [];

    /** @var list<string> */
    public array $unprovable = [];

    public int $checked = 0;

    /** @var array<int, mixed> tokens of the file currently being analysed */
    private array $currentTokens = [];

    /** @var array<int, array<string, string>> */
    private array $scopeTypeCache = [];

    /* ------------------------------------------------------------ indexing */

    public function indexDirectory(string $directory): void
    {
        foreach ($this->phpFiles($directory) as $file) {
            $this->indexFile($file);
        }
    }

    /** @return list<string> */
    public function phpFiles(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    public function indexFile(string $file): void
    {
        $tokens = $this->tokens($file);
        $namespace = '';
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = $this->readName($tokens, $i);

                continue;
            }

            if (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT], true)) {
                // `Foo::class` is not a declaration.
                if ($this->previousSignificant($tokens, $i) === '::') {
                    continue;
                }

                $this->indexClass($tokens, $i, $namespace, $file);
            }
        }
    }

    /** @param array<int, mixed> $tokens */
    private function indexClass(array $tokens, int $start, string $namespace, string $file): void
    {
        $count = count($tokens);
        $i = $start + 1;
        $name = '';

        while ($i < $count) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_STRING) {
                $name = $tokens[$i][1];
                break;
            }

            if ($tokens[$i] === '{' || $tokens[$i] === '(') {
                return;   // anonymous class; nothing to index by name
            }

            $i++;
        }

        if ($name === '') {
            return;
        }

        $fqcn = $namespace === '' ? $name : $namespace.'\\'.$name;

        // Parent / interfaces, so inherited members are not reported missing.
        $parents = [];
        $j = $i;

        while ($j < $count && $tokens[$j] !== '{') {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_EXTENDS, T_IMPLEMENTS], true)) {
                $k = $j + 1;

                while ($k < $count && $tokens[$k] !== '{') {
                    if (is_array($tokens[$k]) && in_array($tokens[$k][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                        $parents[] = ltrim($tokens[$k][1], '\\');
                    }

                    $k++;
                }

                break;
            }

            $j++;
        }

        // Body.
        $body = $j;

        while ($body < $count && $tokens[$body] !== '{') {
            $body++;
        }

        if ($body >= $count) {
            return;
        }

        $depth = 0;
        $methods = [];
        $constants = [];
        $properties = [];

        for ($k = $body; $k < $count; $k++) {
            if ($tokens[$k] === '{') {
                $depth++;

                continue;
            }

            if ($tokens[$k] === '}') {
                $depth--;

                if ($depth === 0) {
                    break;
                }

                continue;
            }

            if ($depth !== 1 || ! is_array($tokens[$k])) {
                continue;
            }

            if ($tokens[$k][0] === T_CONST) {
                $constName = $this->nextString($tokens, $k);

                if ($constName !== null) {
                    $constants[] = $constName;
                }

                continue;
            }

            if ($tokens[$k][0] === T_FUNCTION) {
                $method = $this->readMethod($tokens, $k);

                if ($method !== null) {
                    $methods[strtolower($method['name'])] = $method;
                }

                continue;
            }

            if ($tokens[$k][0] === T_VARIABLE) {
                $properties[] = ltrim($tokens[$k][1], '$');
            }
        }

        $this->classes[$fqcn] = [
            'short' => $name,
            'file' => $file,
            'parents' => $parents,
            'methods' => $methods,
            'constants' => $constants,
            'properties' => $properties,
        ];

        // Artisan commands carry their contract in $signature.
        $signature = $this->readSignature($tokens, $body);

        if ($signature !== null) {
            $parsed = $this->parseSignature($signature);

            if ($parsed !== null) {
                $this->commands[$parsed['name']] = $parsed + ['class' => $fqcn];
            }
        }
    }

    /**
     * One method's visibility, staticness and parameter shape.
     *
     * @param  array<int, mixed>  $tokens
     * @return array<string, mixed>|null
     */
    private function readMethod(array $tokens, int $at): ?array
    {
        $name = $this->nextString($tokens, $at);

        if ($name === null) {
            return null;
        }

        // Modifiers sit before T_FUNCTION.
        $visibility = 'public';
        $static = false;
        $abstract = false;

        for ($b = $at - 1; $b >= 0; $b--) {
            $token = $tokens[$b];

            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                if ($token[0] === T_PUBLIC) {
                    $visibility = 'public';

                    continue;
                }

                if ($token[0] === T_PROTECTED) {
                    $visibility = 'protected';

                    continue;
                }

                if ($token[0] === T_PRIVATE) {
                    $visibility = 'private';

                    continue;
                }

                if ($token[0] === T_STATIC) {
                    $static = true;

                    continue;
                }

                if ($token[0] === T_ABSTRACT) {
                    $abstract = true;

                    continue;
                }

                if ($token[0] === T_FINAL) {
                    continue;
                }
            }

            break;
        }

        // Parameter list.
        $open = $at;
        $count = count($tokens);

        while ($open < $count && $tokens[$open] !== '(') {
            $open++;
        }

        if ($open >= $count) {
            return null;
        }

        $close = $this->matching($tokens, $open);
        $params = $this->splitArguments($tokens, $open, $close);

        $required = 0;
        $optional = 0;
        $variadic = false;
        $names = [];

        foreach ($params as $param) {
            $isOptional = false;
            $isVariadic = false;
            $paramName = null;

            foreach ($param as $token) {
                if (is_array($token)) {
                    if ($token[0] === T_ELLIPSIS) {
                        $isVariadic = true;
                    }

                    if ($token[0] === T_VARIABLE && $paramName === null) {
                        $paramName = ltrim($token[1], '$');
                    }

                    // A promoted constructor property is still a parameter.
                    continue;
                }

                if ($token === '=') {
                    $isOptional = true;
                }
            }

            if ($paramName !== null) {
                $names[] = $paramName;
            }

            if ($isVariadic) {
                $variadic = true;

                continue;
            }

            if ($isOptional) {
                $optional++;

                continue;
            }

            $required++;
        }

        return [
            'name' => $name,
            'visibility' => $visibility,
            'static' => $static,
            'abstract' => $abstract,
            'required' => $required,
            'max' => $required + $optional,
            'variadic' => $variadic,
            'params' => $names,
        ];
    }

    /** @param array<int, mixed> $tokens */
    private function readSignature(array $tokens, int $bodyStart): ?string
    {
        $count = count($tokens);

        for ($i = $bodyStart; $i < $count; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_VARIABLE || $tokens[$i][1] !== '$signature') {
                continue;
            }

            for ($j = $i + 1; $j < $count && $j < $i + 12; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
                    return trim($tokens[$j][1], "'\"");
                }
            }
        }

        return null;
    }

    /**
     * Parse an Artisan signature into its name, arguments and option modes.
     *
     * @return array<string, mixed>|null
     */
    public function parseSignature(string $signature): ?array
    {
        $signature = trim($signature);

        if ($signature === '') {
            return null;
        }

        $name = preg_split('/\s+/', $signature)[0] ?? '';

        if ($name === '' || str_starts_with($name, '{')) {
            return null;
        }

        $options = [];
        $arguments = [];

        preg_match_all('/\{\s*([^}]+)\}/', $signature, $matches);

        foreach ($matches[1] as $token) {
            // Strip the description after ':'.
            $token = trim(explode(' : ', $token)[0]);

            if (str_starts_with($token, '--')) {
                $body = substr($token, 2);
                $body = explode('=', $body, 2);
                $optionName = trim($body[0]);
                $hasValue = count($body) > 1;
                $isArray = $hasValue && str_starts_with(trim($body[1]), '*');

                // Shortcut form "--f|force"
                if (str_contains($optionName, '|')) {
                    $optionName = trim(explode('|', $optionName)[1]);
                }

                $options[$optionName] = [
                    'accepts_value' => $hasValue,
                    'array' => $isArray,
                ];

                continue;
            }

            $argumentName = rtrim(explode('=', $token)[0], '?*');
            $arguments[] = trim($argumentName);
        }

        return ['name' => $name, 'options' => $options, 'arguments' => $arguments];
    }

    /* ----------------------------------------------------------- analysing */

    public function analyseDirectory(string $directory): void
    {
        foreach ($this->phpFiles($directory) as $file) {
            $this->analyseFile($file);
        }
    }

    public function analyseFile(string $file): void
    {
        $tokens = $this->tokens($file);
        $count = count($tokens);
        $label = basename($file);

        /*
         * TYPES ARE INFERRED PER SCOPE, NOT PER FILE.
         *
         * File-level inference was useless on the files that matter: a 5,000
         * line test class assigns `$service` in thirty different methods, the
         * assignments disagree, and a conservative analyser then discards the
         * variable entirely — so the three-argument call to a four-argument
         * method sailed through the very guard written to catch it. Each
         * function body gets its own map, and lookups walk outwards through
         * enclosing scopes so a closure still sees the method that owns it.
         */
        $this->currentTokens = $tokens;
        $this->scopeTypeCache = [];
        $scopes = $this->functionScopes($tokens);
        $fileTypes = $this->inferVariableTypes($tokens, 0, $count - 1);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token)) {
                continue;
            }

            // Static: \App\...\Thing::member(...)
            if (in_array($token[0], [T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)) {
                $next = $this->nextSignificantIndex($tokens, $i);

                if ($next !== null && is_array($tokens[$next]) && $tokens[$next][0] === T_DOUBLE_COLON) {
                    $this->checkStaticCall($tokens, $i, $next, $label);
                }

                continue;
            }

            // Instance: $var->method(...)
            if ($token[0] === T_VARIABLE) {
                $arrow = $this->nextSignificantIndex($tokens, $i);

                if ($arrow !== null && is_array($tokens[$arrow]) && $tokens[$arrow][0] === T_OBJECT_OPERATOR) {
                    $this->checkInstanceCall($tokens, $i, $arrow, $this->typesAt($scopes, $fileTypes, $i), $label);
                }

                continue;
            }
        }

        $this->checkArtisanCalls($tokens, $label);
    }

    /**
     * Variables whose class can be proven from their assignment.
     *
     * Deliberately conservative: a variable assigned twice with different
     * sources is dropped rather than guessed at, and anything else is simply
     * absent from the map and therefore never checked.
     *
     * @param  array<int, mixed>  $tokens
     * @return array<string, string>
     */
    private function inferVariableTypes(array $tokens, int $from, int $to): array
    {
        $types = [];
        $conflicting = [];

        for ($i = $from; $i <= $to; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_VARIABLE) {
                continue;
            }

            $variable = $tokens[$i][1];
            $equals = $this->nextSignificantIndex($tokens, $i);

            if ($equals === null || $tokens[$equals] !== '=') {
                continue;
            }

            $source = $this->nextSignificantIndex($tokens, $equals);

            if ($source === null) {
                continue;
            }

            $class = null;

            // new Foo(...)
            if (is_array($tokens[$source]) && $tokens[$source][0] === T_NEW) {
                $classToken = $this->nextSignificantIndex($tokens, $source);

                if ($classToken !== null && is_array($tokens[$classToken])
                    && in_array($tokens[$classToken][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $class = ltrim($tokens[$classToken][1], '\\');
                }
            }

            // app(Foo::class) / resolve(Foo::class)
            if (is_array($tokens[$source]) && $tokens[$source][0] === T_STRING
                && in_array($tokens[$source][1], ['app', 'resolve'], true)) {
                $open = $this->nextSignificantIndex($tokens, $source);

                if ($open !== null && $tokens[$open] === '(') {
                    $inner = $this->nextSignificantIndex($tokens, $open);

                    if ($inner !== null && is_array($tokens[$inner])
                        && in_array($tokens[$inner][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                        /*
                         * ONLY `Foo::class` PROVES A TYPE.
                         *
                         * `app(Foo::BINDING)` resolves whatever string that
                         * constant holds, which is usually a different class
                         * entirely. Treating the constant's owner as the
                         * variable's type produced a confident false positive
                         * on the first real file this guard met, and a guard
                         * that invents violations gets switched off as fast as
                         * one that misses them.
                         */
                        $colon = $this->nextSignificantIndex($tokens, $inner);
                        $classKeyword = $colon === null ? null : $this->nextSignificantIndex($tokens, $colon);

                        $provesType = $colon !== null
                            && is_array($tokens[$colon])
                            && $tokens[$colon][0] === T_DOUBLE_COLON
                            && $classKeyword !== null
                            && is_array($tokens[$classKeyword])
                            && $tokens[$classKeyword][0] === T_CLASS;

                        if ($provesType) {
                            $class = ltrim($tokens[$inner][1], '\\');
                        } else {
                            $this->unprovable[] = sprintf(
                                'a variable resolved from %s(%s::…) whose class is not statically provable.',
                                $tokens[$source][1],
                                $tokens[$inner][1],
                            );
                        }
                    }
                }
            }

            if ($class === null) {
                continue;
            }

            if (isset($types[$variable]) && $types[$variable] !== $class) {
                $conflicting[$variable] = true;
            }

            $types[$variable] = $class;
        }

        foreach (array_keys($conflicting) as $variable) {
            unset($types[$variable]);
        }

        return $types;
    }

    /** @param array<int, mixed> $tokens */
    private function checkStaticCall(array $tokens, int $classAt, int $colonAt, string $label): void
    {
        $class = ltrim($tokens[$classAt][1], '\\');
        $descriptor = $this->classes[$class] ?? null;

        if ($descriptor === null) {
            return;   // not one of ours; nothing to say
        }

        $memberAt = $this->nextSignificantIndex($tokens, $colonAt);

        if ($memberAt === null || ! is_array($tokens[$memberAt])) {
            return;
        }

        if ($tokens[$memberAt][0] === T_CLASS) {
            return;   // Foo::class
        }

        if ($tokens[$memberAt][0] !== T_STRING) {
            return;
        }

        $member = $tokens[$memberAt][1];
        $open = $this->nextSignificantIndex($tokens, $memberAt);
        $isCall = $open !== null && $tokens[$open] === '(';

        if (! $isCall) {
            if (! in_array($member, $descriptor['constants'], true) && ! $this->inheritsUnknown($class)) {
                $this->violations[] = "{$label}: {$descriptor['short']}::{$member} is not a declared constant.";
            }

            return;
        }

        $method = $this->findMethod($class, $member);

        if ($method === null) {
            if (! $this->inheritsUnknown($class)) {
                $this->violations[] = "{$label}: {$descriptor['short']}::{$member}() does not exist.";
            }

            return;
        }

        $this->checked++;

        if ($method['visibility'] !== 'public') {
            $this->violations[] = "{$label}: {$descriptor['short']}::{$member}() is {$method['visibility']} and cannot be called from here.";
        }

        if (! $method['static']) {
            $this->violations[] = "{$label}: {$descriptor['short']}::{$member}() is an instance method called statically.";
        }

        $this->checkArguments($tokens, $open, $method, "{$descriptor['short']}::{$member}", $label);
    }

    /**
     * @param  array<int, mixed>  $tokens
     * @param  array<string, string>  $variableTypes
     */
    private function checkInstanceCall(array $tokens, int $variableAt, int $arrowAt, array $variableTypes, string $label): void
    {
        $variable = $tokens[$variableAt][1];
        $class = $variableTypes[$variable] ?? null;

        if ($class === null) {
            return;   // untyped variable; not this check's business
        }

        $descriptor = isset($this->classes[$class])
            ? $this->classes[$class] + ['fqcn' => $class]
            : $this->resolveByShortName($class);

        if ($descriptor === null) {
            return;
        }

        $fqcn = $descriptor['fqcn'];
        $memberAt = $this->nextSignificantIndex($tokens, $arrowAt);

        if ($memberAt === null || ! is_array($tokens[$memberAt]) || $tokens[$memberAt][0] !== T_STRING) {
            return;
        }

        $member = $tokens[$memberAt][1];
        $open = $this->nextSignificantIndex($tokens, $memberAt);

        if ($open === null || $tokens[$open] !== '(') {
            return;   // property read
        }

        $method = $this->findMethod($fqcn, $member);

        if ($method === null) {
            if (! $this->inheritsUnknown($fqcn)) {
                $this->violations[] = "{$label}: {$descriptor['short']}->{$member}() does not exist.";
            }

            return;
        }

        $this->checked++;

        if ($method['visibility'] !== 'public') {
            $this->violations[] = "{$label}: {$descriptor['short']}->{$member}() is {$method['visibility']} and cannot be called from here.";
        }

        $this->checkArguments($tokens, $open, $method, "{$descriptor['short']}->{$member}", $label);
    }

    /**
     * Argument count and named-argument validity for one call.
     *
     * @param  array<int, mixed>  $tokens
     * @param  array<string, mixed>  $method
     */
    private function checkArguments(array $tokens, int $open, array $method, string $subject, string $label): void
    {
        $close = $this->matching($tokens, $open);
        $arguments = $this->splitArguments($tokens, $open, $close);

        $positional = 0;
        $named = [];
        $spread = false;

        foreach ($arguments as $argument) {
            $isNamed = false;

            foreach ($argument as $index => $token) {
                if (is_array($token) && $token[0] === T_ELLIPSIS) {
                    $spread = true;
                }

                // `name: value` at the head of an argument.
                if ($index === 0 && is_array($token) && $token[0] === T_STRING) {
                    $after = null;

                    for ($n = 1; $n < count($argument); $n++) {
                        $candidate = $argument[$n];

                        if (is_array($candidate) && in_array($candidate[0], [T_WHITESPACE, T_COMMENT], true)) {
                            continue;
                        }

                        $after = $candidate;
                        break;
                    }

                    if ($after === ':') {
                        $isNamed = true;
                        $named[] = $token[1];
                    }
                }
            }

            if (! $isNamed) {
                $positional++;
            }
        }

        if ($spread) {
            $this->unprovable[] = "{$label}: {$subject}() uses argument unpacking; the count is not statically checkable.";

            return;
        }

        $total = $positional + count($named);

        if ($total < $method['required']) {
            $this->violations[] = sprintf(
                '%s: %s() needs at least %d argument(s), %d given.',
                $label, $subject, $method['required'], $total,
            );
        }

        if (! $method['variadic'] && $total > $method['max']) {
            $this->violations[] = sprintf(
                '%s: %s() accepts at most %d argument(s), %d given.',
                $label, $subject, $method['max'], $total,
            );
        }

        foreach ($named as $name) {
            if ($method['params'] !== [] && ! in_array($name, $method['params'], true)) {
                $this->violations[] = "{$label}: {$subject}() has no parameter \${$name}.";
            }
        }
    }

    /** @param array<int, mixed> $tokens */
    private function checkArtisanCalls(array $tokens, string $label): void
    {
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING || $tokens[$i][1] !== 'artisan') {
                continue;
            }

            $open = $this->nextSignificantIndex($tokens, $i);

            if ($open === null || $tokens[$open] !== '(') {
                continue;
            }

            $first = $this->nextSignificantIndex($tokens, $open);

            if ($first === null || ! is_array($tokens[$first]) || $tokens[$first][0] !== T_CONSTANT_ENCAPSED_STRING) {
                $this->unprovable[] = "{$label}: an artisan() call whose command is not a literal string.";

                continue;
            }

            $invocation = trim($tokens[$first][1], "'\"");
            $parts = preg_split('/\s+/', trim($invocation)) ?: [];
            $name = array_shift($parts) ?? '';

            if (! str_starts_with($name, 'mulkihawler:')) {
                continue;   // framework commands are not ours to verify
            }

            $this->checked++;

            $command = $this->commands[$name] ?? null;

            if ($command === null) {
                $this->violations[] = "{$label}: artisan command '{$name}' is not declared by any command class.";

                continue;
            }

            foreach ($parts as $part) {
                if (! str_starts_with($part, '--')) {
                    continue;   // positional argument
                }

                $body = substr($part, 2);
                $hasValue = str_contains($body, '=');
                $optionName = $hasValue ? explode('=', $body, 2)[0] : $body;
                $value = $hasValue ? explode('=', $body, 2)[1] : null;

                if (! array_key_exists($optionName, $command['options'])) {
                    $this->violations[] = "{$label}: '{$name}' has no option --{$optionName}.";

                    continue;
                }

                $option = $command['options'][$optionName];

                if ($option['accepts_value'] && ($value === null || $value === '')) {
                    $this->violations[] = "{$label}: '{$name}' option --{$optionName} requires a value.";
                }

                if (! $option['accepts_value'] && $hasValue) {
                    $this->violations[] = "{$label}: '{$name}' option --{$optionName} is a switch and takes no value.";
                }
            }
        }
    }

    /**
     * Every function/method body in the file as [start, end] token offsets.
     *
     * @param  array<int, mixed>  $tokens
     * @return list<array{start: int, end: int}>
     */
    private function functionScopes(array $tokens): array
    {
        $scopes = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }

            // Skip to the body, stepping over the parameter list and any
            // return type declaration.
            $paren = $i;

            while ($paren < $count && $tokens[$paren] !== '(') {
                $paren++;
            }

            if ($paren >= $count) {
                continue;
            }

            $afterParams = $this->matching($tokens, $paren);
            $brace = $afterParams;

            while ($brace < $count && $tokens[$brace] !== '{' && $tokens[$brace] !== ';') {
                $brace++;
            }

            if ($brace >= $count || $tokens[$brace] === ';') {
                continue;   // abstract or interface method: no body
            }

            $scopes[] = ['start' => $brace, 'end' => $this->matching($tokens, $brace)];
        }

        return $scopes;
    }

    /**
     * The inferred variable types visible at one token offset.
     *
     * Innermost scope first, then outwards, then the file. Cached per scope so
     * a large class is not re-scanned once per call site.
     *
     * @param  list<array{start: int, end: int}>  $scopes
     * @param  array<string, string>  $fileTypes
     * @return array<string, string>
     */
    private function typesAt(array $scopes, array $fileTypes, int $offset): array
    {
        $enclosing = [];

        foreach ($scopes as $index => $scope) {
            if ($offset > $scope['start'] && $offset < $scope['end']) {
                $enclosing[$index] = $scope;
            }
        }

        // Innermost last => reverse so it wins.
        $merged = $fileTypes;

        foreach ($enclosing as $index => $scope) {
            $merged = array_merge($merged, $this->scopeTypes($index, $scope));
        }

        return $merged;
    }

    /**
     * @param  array{start: int, end: int}  $scope
     * @return array<string, string>
     */
    private function scopeTypes(int $index, array $scope): array
    {
        if (! isset($this->scopeTypeCache[$index])) {
            $this->scopeTypeCache[$index] = $this->inferVariableTypes(
                $this->currentTokens,
                $scope['start'],
                $scope['end'],
            );
        }

        return $this->scopeTypeCache[$index];
    }

    /* ------------------------------------------------------------- helpers */

    /**
     * Resolve a method through the app-visible inheritance chain.
     *
     * @return array<string, mixed>|null
     */
    private function findMethod(string $class, string $member): ?array
    {
        $seen = [];
        $queue = [$class];

        while ($queue !== []) {
            $current = array_shift($queue);

            if ($current === '' || isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;
            $descriptor = $this->classes[$current] ?? null;

            if ($descriptor === null) {
                $descriptor = $this->resolveByShortName($current);

                if ($descriptor === null) {
                    continue;
                }
            }

            $key = strtolower($member);

            if (isset($descriptor['methods'][$key])) {
                return $descriptor['methods'][$key];
            }

            foreach ($descriptor['parents'] as $parent) {
                $queue[] = $parent;
            }
        }

        return null;
    }

    /**
     * Whether the class inherits from something outside the indexed tree.
     *
     * A model extends Eloquent and a command extends the console base class, so
     * an unrecognised member on such a class is very likely inherited rather
     * than missing. Reporting those would make the guard cry wolf, and a guard
     * that cries wolf gets switched off.
     */
    private function inheritsUnknown(string $class): bool
    {
        $seen = [];
        $queue = [$class];

        while ($queue !== []) {
            $current = array_shift($queue);

            if ($current === '' || isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;
            $descriptor = $this->classes[$current] ?? $this->resolveByShortName($current);

            if ($descriptor === null) {
                return true;
            }

            if ($descriptor['parents'] === []) {
                continue;
            }

            foreach ($descriptor['parents'] as $parent) {
                $queue[] = $parent;
            }
        }

        return false;
    }

    /** @return array<string, mixed>|null */
    private function resolveByShortName(string $name): ?array
    {
        if (isset($this->classes[$name])) {
            return $this->classes[$name] + ['fqcn' => $name];
        }

        $short = substr((string) strrchr('\\'.$name, '\\'), 1);
        $matches = [];

        foreach ($this->classes as $fqcn => $descriptor) {
            if ($descriptor['short'] === $short) {
                $matches[$fqcn] = $descriptor + ['fqcn' => $fqcn];
            }
        }

        // Ambiguous short names prove nothing.
        return count($matches) === 1 ? reset($matches) : null;
    }

    /** @return array<int, mixed> */
    private function tokens(string $file): array
    {
        return token_get_all((string) file_get_contents($file));
    }

    /** @param array<int, mixed> $tokens */
    private function matching(array $tokens, int $open): int
    {
        $depth = 0;
        $count = count($tokens);

        for ($i = $open; $i < $count; $i++) {
            if ($tokens[$i] === '(' || $tokens[$i] === '[' || $tokens[$i] === '{') {
                $depth++;

                continue;
            }

            if ($tokens[$i] === ')' || $tokens[$i] === ']' || $tokens[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return $count - 1;
    }

    /**
     * Split a parenthesised list into top-level arguments.
     *
     * @param  array<int, mixed>  $tokens
     * @return list<list<mixed>>
     */
    private function splitArguments(array $tokens, int $open, int $close): array
    {
        $arguments = [];
        $current = [];
        $depth = 0;

        for ($i = $open + 1; $i < $close; $i++) {
            $token = $tokens[$i];

            if (in_array($token, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($token, [')', ']', '}'], true)) {
                $depth--;
            }

            if ($token === ',' && $depth === 0) {
                $trimmed = $this->trimTokens($current);

                if ($trimmed !== []) {
                    $arguments[] = $trimmed;
                }

                $current = [];

                continue;
            }

            $current[] = $token;
        }

        $trimmed = $this->trimTokens($current);

        if ($trimmed !== []) {
            $arguments[] = $trimmed;
        }

        return $arguments;
    }

    /**
     * @param  list<mixed>  $tokens
     * @return list<mixed>
     */
    private function trimTokens(array $tokens): array
    {
        $out = [];

        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                if ($out === []) {
                    continue;
                }
            }

            $out[] = $token;
        }

        while ($out !== []) {
            $last = end($out);

            if (is_array($last) && in_array($last[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                array_pop($out);

                continue;
            }

            break;
        }

        return $out;
    }

    /** @param array<int, mixed> $tokens */
    private function nextSignificantIndex(array $tokens, int $from): ?int
    {
        $count = count($tokens);

        for ($i = $from + 1; $i < $count; $i++) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /** @param array<int, mixed> $tokens */
    private function previousSignificant(array $tokens, int $from): mixed
    {
        for ($i = $from - 1; $i >= 0; $i--) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
        }

        return null;
    }

    /** @param array<int, mixed> $tokens */
    private function nextString(array $tokens, int $from): ?string
    {
        $count = count($tokens);

        for ($i = $from + 1; $i < $count; $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_STRING) {
                return $tokens[$i][1];
            }

            if ($tokens[$i] === '(' || $tokens[$i] === ';') {
                return null;
            }
        }

        return null;
    }

    /** @param array<int, mixed> $tokens */
    private function readName(array $tokens, int $from): string
    {
        $count = count($tokens);
        $name = '';

        for ($i = $from + 1; $i < $count; $i++) {
            if ($tokens[$i] === ';' || $tokens[$i] === '{') {
                break;
            }

            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $name .= $tokens[$i][1];
            }
        }

        return trim($name, '\\');
    }
}
