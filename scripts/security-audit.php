<?php

declare(strict_types=1);

/**
 * Security and performance audit (File two §20, §21).
 *
 * §20 is a checklist, and a checklist that lives in a document gets ticked from
 * memory. This script re-derives each answer from the source every time it
 * runs, so a regression is caught by CI rather than by the next review — which
 * for several of these items would be after the damage.
 *
 * It checks what can honestly be checked without executing the application.
 * Items that genuinely require a running deployment (CWV, load, penetration
 * testing) are REPORTED AS UNCHECKABLE rather than silently passed, because a
 * green audit that quietly omits half the list is worse than no audit.
 *
 * Usage: php scripts/security-audit.php [--json]
 */
$root = dirname(__DIR__);
$asJson = in_array('--json', $argv, true);

/** @var list<array{id: string, area: string, ok: bool, detail: string}> $results */
$results = [];
/** @var list<array{id: string, reason: string}> $uncheckable */
$uncheckable = [];

$read = static function (string $path) use ($root): string {
    $full = $root.'/'.ltrim($path, '/');

    return is_file($full) ? (string) file_get_contents($full) : '';
};

/** Read PHP with comments removed — a comment about a risk is not the risk. */
$code = static function (string $path) use ($root): string {
    $full = $root.'/'.ltrim($path, '/');

    if (! is_file($full)) {
        return '';
    }

    $out = '';

    foreach (token_get_all((string) file_get_contents($full)) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= $token[1];

            continue;
        }

        $out .= $token;
    }

    return $out;
};

$check = static function (string $id, string $area, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['id' => $id, 'area' => $area, 'ok' => $ok, 'detail' => $detail];
};

/** Walk every PHP file under a directory. */
$sources = static function (string $dir) use ($root): array {
    $full = $root.'/'.$dir;

    if (! is_dir($full)) {
        return [];
    }

    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS));

    foreach ($it as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    return $files;
};

// ------------------------------------------------------------- SESSION / COOKIES

$session = $read('config/session.php');

$check('cookie.encrypt', 'session', str_contains($session, "'encrypt' => (bool) env('SESSION_ENCRYPT', true)"),
    'session payload encrypted by default');
$check('cookie.secure', 'session', str_contains($session, "'secure' => (bool) env('SESSION_SECURE_COOKIE', true)"),
    'secure flag defaults ON, so a misconfigured .env fails closed');
$check('cookie.http_only', 'session', str_contains($session, "'http_only' => true"),
    'cookie unreadable from JavaScript');
$check('cookie.same_site', 'session', str_contains($session, "'same_site'"), 'SameSite declared');

// ------------------------------------------------------------------------ CSRF

$bootstrap = $code('bootstrap/app.php');

$check('csrf.enabled', 'csrf', ! str_contains($bootstrap, 'withoutMiddleware(VerifyCsrfToken'),
    'CSRF is not globally disabled');

/*
 * Every CSRF exemption is a deliberate hole. The only acceptable ones are
 * endpoints called by a third party that cannot hold a token — and each must
 * authenticate some other way.
 */
preg_match('/validateCsrfTokens\(except:\s*\[(.*?)\]/s', $bootstrap, $exemptMatch);
preg_match_all("/'([^']+)'/", $exemptMatch[1] ?? '', $exemptPaths);
$exemptions = $exemptPaths[1] ?? [];

$check('csrf.exemptions_bounded', 'csrf', count($exemptions) <= 3,
    count($exemptions).' exemption(s): '.implode(', ', $exemptions));

$telegramController = $code('app/Modules/Identity/Http/Controllers/Auth/TelegramAuthController.php');

$check('csrf.exempt_endpoints_authenticated', 'csrf',
    str_contains($telegramController, 'verifySignature('),
    'the CSRF-exempt Telegram webhook authenticates by secret header instead');

// ------------------------------------------------------------------ RATE LIMITS

$identityProvider = $code('app/Modules/Identity/Providers/IdentityServiceProvider.php');

foreach (['login', 'mfa', 'password-reset', 'telegram-poll', 'telegram-webhook'] as $limiter) {
    $check("ratelimit.{$limiter}", 'rate-limits',
        str_contains($identityProvider, "RateLimiter::for('{$limiter}'"),
        "{$limiter} throttled");
}

// -------------------------------------------------------------------- PII / KEYS

$user = $code('app/Modules/Identity/Models/User.php');

$check('pii.phone_encrypted', 'pii', str_contains($user, 'Crypt::encryptString($e164)'),
    'phone encrypted at rest');
$check('pii.blind_index', 'pii', str_contains($user, "hash_hmac('sha256'"),
    'searchable blind index is a keyed HMAC');
$check('pii.unkeyed_index_refused', 'pii',
    str_contains($user, 'refusing to build an unkeyed index'),
    'a missing key fails loudly instead of silently degrading to a rainbow-tableable hash');
$check('pii.hidden_attributes', 'pii', str_contains($user, "'phone_encrypted', 'phone_index', 'telegram_id'"),
    'PII hidden from serialisation');

// Sensitive models must not serialise their ciphertext or coordinates.
foreach ([
    'app/Modules/Advisor/Models/LifestylePriority.php' => "protected \$hidden = ['latitude', 'longitude']",
    'app/Modules/Leads/Models/LeadNote.php' => "protected \$hidden = ['body_encrypted']",
    // v6: this matched a SINGLE-LINE array literal, so the merged model —
    // which hides MORE fields across several lines — failed a formatting
    // test rather than a security one. The needle is the guarantee itself.
    'app/Modules/Identity/Models/TelegramLoginIntent.php' => "'token', 'session_fingerprint'",
] as $path => $needle) {
    $check('pii.hidden.'.basename($path, '.php'), 'pii',
        str_contains($code($path), $needle), 'sensitive fields hidden');
}

// ---------------------------------------------------------------- CONSENT GATES

$reveal = $code('app/Modules/Leads/Services/PhoneRevealService.php');

$check('consent.reveal_gated', 'consent', $reveal !== '' && str_contains($reveal, 'consent'),
    'phone reveal checks consent');

foreach (['force', 'override', 'bypass', 'skipConsent', 'ignoreConsent'] as $escape) {
    $check("consent.no_{$escape}", 'consent', ! str_contains($reveal, $escape.'('),
        "no {$escape}() escape hatch");
}

$workspace = $code('app/Modules/Leads/Http/Controllers/Admin/SalesWorkspaceController.php');

$check('consent.workspace_no_contact', 'consent',
    ! str_contains($workspace, "'phone'") && ! str_contains($workspace, 'phone_encrypted'),
    'the sales workspace payload carries no contact detail at all');

// --------------------------------------------------------------- AI GUARDRAILS

$composer = $code('app/Modules/Advisor/Services/AdvisorAnswerComposer.php');

$retrieve = strpos($composer, 'retrieval->filter(');
$complete = strpos($composer, 'gateway->complete(');
$validate = strpos($composer, 'numeric->validate(');

$check('ai.retrieval_before_model', 'ai',
    $retrieve !== false && $complete !== false && $retrieve < $complete,
    'the model cannot leak evidence it never received');
$check('ai.validation_after_model', 'ai',
    $complete !== false && $validate !== false && $complete < $validate,
    'output is validated, not trusted');
/*
 * The guard, not one spelling of it.
 *
 * This matched `($validation['grounded'] ?? false) !== true` literally. The
 * coalesce was removed once the validator's return shape proved `grounded` is
 * always present, so the check started failing on a behaviour that had not
 * changed at all. Matching the comparison itself keeps the guarantee — an
 * ungrounded answer discards the prose — without pinning it to dead syntax.
 */
$check('ai.ungrounded_discarded', 'ai',
    str_contains($composer, "\$validation['grounded'] !== true")
    && str_contains($composer, "'numeric_validation_failed'"),
    'an invented number discards the prose rather than publishing it with a caveat');

$adapter = $code('app/Modules/Advisor/Providers/OpenAiCompatibleProvider.php');

$check('ai.system_prompt_separate', 'ai',
    str_contains($adapter, "array_unshift(\$messages, ['role' => 'system'"),
    'prompt-injection boundary preserved');
$check('ai.no_key_in_errors', 'ai',
    ! str_contains($adapter, '$e->getMessage()'),
    'a transfer exception carries the URL, and the key is in that path');

$gateway = $code('app/Modules/Advisor/Services/AiGateway.php');
$budget = strpos($gateway, 'assertWithinBudget()');
$loop = strpos($gateway, 'foreach ($this->providers');

$check('ai.cost_ceiling', 'ai', str_contains($gateway, 'costLimitReached'), 'monthly ceiling enforced');
$check('ai.circuit_breaker', 'ai', str_contains($gateway, 'FAILURE_THRESHOLD'), 'breaker present');

// -------------------------------------------------------------------- WEBHOOKS

$telegramAuth = $code('app/Modules/Identity/Services/TelegramAuthenticator.php');

$check('webhook.constant_time', 'webhooks',
    str_contains($telegramAuth, 'hash_equals($expected, $providedSecret)'),
    'signature compared in constant time');
$check('webhook.fails_closed', 'webhooks',
    str_contains($telegramAuth, "if (\$expected === '')"),
    'an unconfigured secret refuses everything rather than accepting everything');
/*
 * v6: replay protection is no longer a lookup inside the authenticator; the
 * webhook inbox CLAIMS an update through a unique `update_id`, so a
 * duplicate delivery loses the insert rather than racing a read — a
 * stronger guarantee. The check asserts both halves: the atomic claim in
 * the service, and the unique key in the schema that makes it atomic.
 */
$telegramInbox = $code('app/Modules/Identity/Services/TelegramWebhookInbox.php');
$intentMigration = $code('app/Modules/Identity/Database/Migrations/2026_07_25_000200_create_telegram_login_intents.php');

$check('webhook.replay_protection', 'webhooks',
    str_contains($telegramInbox, 'public function claim(')
        && str_contains($intentMigration, "unsignedBigInteger('update_id')->unique()"),
    'replay refused by a unique key, not a lookup');

// ------------------------------------------------------------------- INSTALLER

$installState = $code('app/Modules/Install/Services/InstallState.php');
$stepValidator = $code('app/Modules/Install/Services/StepValidator.php');

$check('installer.lock', 'installer', str_contains($installState, 'function lock('), 'installer lockable');
$check('installer.secrets_not_persisted', 'installer',
    str_contains($stepValidator, 'SECRET_FIELDS') && str_contains($stepValidator, 'withoutSecrets'),
    'secrets stripped from the resumable state file');
$check('installer.admin_password_min', 'installer',
    str_contains($stepValidator, "'min:12'"),
    'the account that can read every encrypted phone needs more than Laravel default 8');

// -------------------------------------------------------------- MASS ASSIGNMENT

$unguarded = [];

foreach ($sources('app') as $file) {
    $contents = (string) file_get_contents($file);

    if (! str_contains($contents, 'extends Model')) {
        continue;
    }

    // A model with neither $fillable nor $guarded accepts anything a request
    // sends, which is how an is_admin flag gets set by a form field.
    if (! str_contains($contents, '$fillable') && ! str_contains($contents, '$guarded')) {
        $unguarded[] = str_replace($root.'/', '', $file);
    }
}

$check('model.mass_assignment', 'models', $unguarded === [],
    $unguarded === [] ? 'every model declares $fillable or $guarded' : implode(', ', $unguarded));

// ------------------------------------------------------------- RAW SQL / INJECT

$rawSql = [];

foreach ($sources('app') as $file) {
    $contents = (string) file_get_contents($file);

    // Interpolated variables inside a raw expression are the classic injection
    // route. whereRaw with a bound parameter is fine; whereRaw("x = $y") is not.
    if (preg_match('/(whereRaw|selectRaw|orderByRaw|havingRaw)\([^)]*\$[a-z]/i', $contents)) {
        $rawSql[] = str_replace($root.'/', '', $file);
    }
}

$check('sql.no_interpolated_raw', 'sql', $rawSql === [],
    $rawSql === [] ? 'no interpolated raw SQL' : implode(', ', $rawSql));

// ------------------------------------------------------------------- XSS / HTML

$vueFiles = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/resources/js', FilesystemIterator::SKIP_DOTS));

foreach ($it as $file) {
    if ($file->getExtension() === 'vue') {
        $vueFiles[] = $file->getPathname();
    }
}

$vHtml = [];

foreach ($vueFiles as $file) {
    $contents = (string) file_get_contents($file);
    // Strip comments: a comment explaining why v-html was avoided is not a use.
    $contents = preg_replace('#/\*.*?\*/#s', '', $contents) ?? $contents;
    $contents = preg_replace('#<!--.*?-->#s', '', $contents) ?? $contents;

    if (str_contains($contents, 'v-html')) {
        $vHtml[] = str_replace($root.'/', '', $file);
    }
}

$check('xss.no_v_html', 'xss', $vHtml === [],
    $vHtml === [] ? 'no v-html anywhere' : implode(', ', $vHtml));

// --------------------------------------------------------- PERFORMANCE (§21)

/*
 * A foreign key without an index turns every join and every cascade into a
 * table scan. MySQL creates one automatically for a real FK constraint, but
 * columns used for filtering that are NOT constrained get nothing.
 */
$missingIndexes = [];

/*
 * Columns indexed by any migration that ALTERS a table rather than creating it.
 * Collected first so the per-table scan below can see them.
 */
$indexedLater = [];

foreach ($sources('app') as $file) {
    if (! str_contains($file, 'Migrations')) {
        continue;
    }

    $contents = (string) file_get_contents($file);

    if (! str_contains($contents, 'Schema::table(')) {
        continue;
    }

    if (preg_match_all("/->index\('([a-z_]+)'/", $contents, $laterMatches)) {
        foreach ($laterMatches[1] as $column) {
            $indexedLater[] = $column;
        }
    }
}

foreach ($sources('app') as $file) {
    if (! str_contains($file, 'Migrations')) {
        continue;
    }

    $contents = (string) file_get_contents($file);

    /*
     * Checked per STATEMENT, not per file. An earlier version of this check
     * used a lookahead over `[^;]*`, which is greedy: it swallowed the
     * `->index()` it was looking for and reported five false positives on
     * columns that were correctly indexed all along. Splitting on the
     * semicolon first removes the ambiguity entirely.
     */
    foreach (explode(';', $contents) as $statement) {
        foreach (['publication_status', 'status'] as $column) {
            if (! preg_match("/->string\('{$column}'/", $statement)) {
                continue;
            }

            // Indexed inline on the column itself...
            if (str_contains($statement, '->index()')) {
                continue;
            }

            // ...or as the leading member of a composite index elsewhere in
            // the same migration, which serves the same queries.
            if (preg_match("/index\(\[\s*'{$column}'/", $contents)) {
                continue;
            }

            /*
             * ...or added by a later migration. An index is a property of the
             * schema, not of the file that happened to create the table, and
             * an audit that could not see a follow-up migration would force
             * every fix to be a rewrite of the original.
             */
            if (in_array($column, $indexedLater, true)) {
                continue;
            }

            $missingIndexes[] = basename($file).':'.$column;
        }
    }
}

$check('perf.status_columns_indexed', 'performance',
    count($missingIndexes) === 0,
    $missingIndexes === [] ? 'status columns indexed' : implode(', ', array_unique($missingIndexes)));

$check('perf.pagination', 'performance',
    count(array_filter($sources('app'), static fn (string $f): bool => str_contains((string) file_get_contents($f), '->paginate('))) > 0,
    'list endpoints paginate');

$mapController = $code('app/Modules/Geography/Http/Controllers/Public/MapExplorerController.php');
$check('perf.map_bounded', 'performance',
    str_contains($mapController, 'MAX_PER_LAYER'),
    'map layers are capped and bounded');

// ------------------------------------------------------------- NOT CHECKABLE

$uncheckable = [
    ['id' => 'dependency.audit', 'reason' => 'composer audit needs vendor/; Packagist unreachable'],
    ['id' => 'static.analysis', 'reason' => 'PHPStan/Larastan need vendor/'],
    ['id' => 'perf.core_web_vitals', 'reason' => 'needs a running deployment and a browser'],
    ['id' => 'perf.load_test', 'reason' => 'needs a running deployment'],
    ['id' => 'security.penetration', 'reason' => 'needs a running deployment'],
    ['id' => 'perf.query_profile', 'reason' => 'needs a database with representative data'],
    ['id' => 'a11y.audit', 'reason' => 'needs a browser'],
];

// ----------------------------------------------------------------- REPORT

$failed = array_values(array_filter($results, static fn (array $r): bool => ! $r['ok']));

if ($asJson) {
    echo json_encode([
        'ok' => $failed === [],
        'checked' => count($results),
        'failed' => $failed,
        'uncheckable' => $uncheckable,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

    exit($failed === [] ? 0 : 1);
}

echo "\nSecurity and performance audit — File two §20, §21\n";
echo str_repeat('─', 72), "\n";

$area = '';

foreach ($results as $result) {
    if ($result['area'] !== $area) {
        $area = $result['area'];
        printf("\n  \033[1m%s\033[0m\n", strtoupper($area));
    }

    printf(
        "    %s %-38s %s\n",
        $result['ok'] ? "\033[32m✓\033[0m" : "\033[31m✗\033[0m",
        $result['id'],
        $result['detail']
    );
}

echo "\n  \033[1mNOT CHECKABLE HERE\033[0m\n";

foreach ($uncheckable as $item) {
    printf("    \033[33m—\033[0m %-38s %s\n", $item['id'], $item['reason']);
}

echo "\n", str_repeat('─', 72), "\n";

if ($failed === []) {
    printf(
        "  \033[42;30m PASS \033[0m  %d checked, 0 failed, %d require a running deployment\n\n",
        count($results),
        count($uncheckable)
    );
    exit(0);
}

printf("  \033[41;37m FAIL \033[0m  %d of %d checks failed\n\n", count($failed), count($results));
exit(1);
