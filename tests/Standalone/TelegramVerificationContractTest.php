<?php

declare(strict_types=1);
use Mulkihawler\Tooling\TestTally;

require_once __DIR__.'/../../scripts/support/TestTally.php';
require_once __DIR__.'/../../app/Modules/Identity/Support/PhoneNumber.php';

use App\Modules\Identity\Support\PhoneNumber;

/*
 * The simplified Telegram verification contract, proven WITHOUT Laravel.
 *
 * The PHPUnit suite covers this flow properly, but it cannot run wherever
 * Packagist is unreachable — which is most of the time in this environment, and
 * the whole reason this directory exists. A change to the registration and
 * verification path is exactly the kind that must not ship on "the tests will
 * run on CI"; so everything provable without a framework is proved here.
 *
 * Two kinds of check:
 *
 *   BEHAVIOURAL, where the code is a pure function. Phone normalisation is the
 *   only one, and it is the highest-value one: it decides whether the number
 *   somebody registers with is the number that signs them in, and it used to be
 *   duplicated across three private methods that did NOT agree.
 *
 *   STRUCTURAL, where the property is about the shape of the source. These are
 *   not a substitute for behaviour, and each is written to fail on the specific
 *   regression it names — "no expiry column" is checked against the migration
 *   text, not against a comment claiming there is none.
 */

$root = dirname(__DIR__, 2);
TestTally::reset();

$ok = static function (string $name, bool $condition): void {
    if ($condition) {
        echo "  pass {$name}\n";
    } else {
        TestTally::fail();
        echo "  FAIL {$name}\n";
    }
};

$read = static function (string $path) use ($root): string {
    $full = $root.'/'.$path;

    return is_file($full) ? (string) file_get_contents($full) : '';
};

/* ------------------------------------------------ phone normalisation */

echo "\n-- phone normalisation\n";

/*
 * Every way an Iraqi mobile number is written must reach ONE canonical form.
 * The blind index is an exact-match HMAC, so two spellings that normalise
 * differently do not produce a near miss — they produce a person who cannot
 * sign in with the number they registered with.
 */
$canonical = '+9647501234567';

foreach ([
    '07501234567',
    '0750 123 4567',
    '0750-123-4567',
    '7501234567',
    '+9647501234567',
    '+964 750 123 4567',
    '9647501234567',
    '009647501234567',
    ' 0750 123 4567 ',
] as $written) {
    $ok(
        sprintf('%-20s normalises to the canonical form', $written),
        PhoneNumber::toE164($written) === $canonical,
    );
}

$ok(
    'a number with no digits does not throw and matches nothing real',
    PhoneNumber::toE164('not a number') === '+964',
);

$ok(
    'two different subscribers do NOT collide',
    PhoneNumber::toE164('07501234567') !== PhoneNumber::toE164('07501234568'),
);

/*
 * The registration controller must delegate rather than keep its own copy.
 * Its private toE164() and PhoneNumber::toE164() disagreeing is the exact
 * failure this consolidation exists to prevent, and a second copy would drift
 * silently.
 */
$registration = $read('app/Modules/Identity/Http/Controllers/Auth/RegistrationController.php');

$ok(
    'registration normalises through the shared helper',
    str_contains($registration, 'return PhoneNumber::toE164($phone);'),
);

$ok(
    'registration keeps no second copy of the normalisation rule',
    ! str_contains($registration, "ltrim(\$digits, '0')"),
);

/* ------------------------------------------------ the token has no clock */

echo "\n-- the token has no clock\n";

$migration = $read('app/Modules/Identity/Database/Migrations/2026_08_09_000100_telegram_verification_tokens.php');
$model = $read('app/Modules/Identity/Models/TelegramVerificationToken.php');
$service = $read('app/Modules/Identity/Services/TelegramVerificationService.php');

$ok('the verification token migration exists', $migration !== '');

/*
 * Checked against the schema BUILDER calls, not against the file text as a
 * whole: the docblock says "expires_at" several times explaining why there
 * isn't one, and a naive substring search would fail on its own explanation.
 */
preg_match_all('/\$table->\w+\(\s*\'(\w+)\'/', $migration, $columns);
$declared = $columns[1];

$ok(
    'the migration declares no expiry column',
    ! in_array('expires_at', $declared, true) && ! in_array('expires', $declared, true),
);

$ok(
    'the migration declares token_hash, token_encrypted, used_at and revoked_at',
    in_array('token_hash', $declared, true)
        && in_array('token_encrypted', $declared, true)
        && in_array('used_at', $declared, true)
        && in_array('revoked_at', $declared, true),
);

/*
 * The usability rule must consult STATE and nothing else. A clock reaching
 * isUsable() would reintroduce expiry without any column to show for it, which
 * is precisely the regression the no-column check above cannot see.
 */
preg_match('/public function isUsable\(\): bool\s*\{(.*?)\n    \}/s', $model, $usable);
$usableBody = $usable[1] ?? '';

$ok('the model defines isUsable()', $usableBody !== '');

$ok(
    'isUsable() reads only used_at and revoked_at',
    str_contains($usableBody, 'used_at')
        && str_contains($usableBody, 'revoked_at')
        && ! str_contains($usableBody, 'now()')
        && ! str_contains($usableBody, 'isFuture')
        && ! str_contains($usableBody, 'expires'),
);

preg_match('/public function scopeUsable\(Builder \$query\): Builder\s*\{(.*?)\n    \}/s', $model, $scope);
$scopeBody = $scope[1] ?? '';

$ok(
    'the usable scope applies the same rule and no clock',
    str_contains($scopeBody, "whereNull('used_at')")
        && str_contains($scopeBody, "whereNull('revoked_at')")
        && ! str_contains($scopeBody, 'now()'),
);

$ok(
    'redemption never rejects a token for being old',
    ! preg_match('/isFuture\(\)|expires_at|->addMinutes\(|->addHours\(|->addDays\(/', $service),
);

/* ------------------------------------------------ one press, no second step */

echo "\n-- one press, no second step\n";

$webhook = $read('app/Modules/Identity/Http/Controllers/Auth/TelegramAuthController.php');

$ok(
    'the webhook redeems the verification token',
    str_contains($webhook, '$this->verification->redeem($token, $from)'),
);

$ok(
    'a successful verification replies once and returns',
    str_contains($webhook, '$this->bot->confirmVerified($chatId, $replyLocale)'),
);

/*
 * The one-press property, asserted where it can actually be broken: the
 * verification branch of the webhook must not park a candidate or ask for a
 * browser confirmation. The account-link branch above it still does both, and
 * must — so the check is scoped to the new branch rather than to the file.
 */
$start = strpos($webhook, 'if ($intent === null) {');
$end = strpos($webhook, 'if ($intent !== null && $senderId !== \'\') {');
$verificationBranch = $start !== false && $end !== false && $end > $start
    ? substr($webhook, $start, $end - $start)
    : '';

$ok('the verification branch is present in the webhook', $verificationBranch !== '');

$ok(
    'the verification branch never requests a browser confirmation',
    $verificationBranch !== ''
        && ! str_contains($verificationBranch, 'requestBrowserConfirmation')
        && ! str_contains($verificationBranch, 'candidate'),
);

$ok(
    'the service never parks a candidate',
    ! str_contains($service, 'candidate_telegram_id'),
);

/*
 * The account-link flow keeps its confirmation. Simplifying registration must
 * not have quietly removed the gate that protects an account which already HAS
 * a Telegram identity — a different flow with a different threat.
 */
$registrar = $read('app/Modules/Identity/Services/TelegramRegistrar.php');

$ok(
    'the account-link flow still requires browser confirmation',
    str_contains($registrar, 'if (self::confirmationRequired()) {')
        && str_contains($registrar, "'pending_confirmation' => true"),
);

$ok(
    'confirmAccountLink is still present and still re-checks conflicts',
    str_contains($registrar, 'public function confirmAccountLink(')
        && str_contains($registrar, 'account_linked_elsewhere'),
);

/* ------------------------------------------------ the three refusals */

echo "\n-- the three refusals\n";

foreach ([
    'a spent token is refused for any other sender' => 'used_token_replay_by_other_sender',
    'an account linked elsewhere is never re-pointed' => 'account_linked_elsewhere',
    'a Telegram identity in use is never reassigned' => 'telegram_id_taken',
    'a revoked token is refused' => "'via' => 'revoked'",
    'a suspended or missing account cannot verify' => 'account_unavailable',
] as $name => $marker) {
    $ok($name, str_contains($service, $marker));
}

$ok(
    'the numeric Telegram id is the identity, never the username',
    str_contains($service, "\$telegramId = (string) (\$from['id'] ?? '');")
        && ! preg_match("/where\('telegram_username'/", $service),
);

$ok(
    'linking runs inside a transaction under a row lock',
    str_contains($service, 'DB::transaction(')
        && str_contains($service, 'lockForUpdate()'),
);

$ok(
    'the unique-constraint race is caught and translated to a refusal',
    str_contains($service, 'catch (UniqueConstraintViolationException)'),
);

/* ------------------------------------------------ no secrets escape */

echo "\n-- no secrets escape\n";

$ok(
    'the model hides both stored forms of the token',
    preg_match("/protected \\\$hidden = \\['token_hash', 'token_encrypted'\\];/", $model) === 1,
);

/*
 * Audit calls must carry the token's ID, never its value or its digest. Checked
 * by looking at what is passed alongside every audit call in the service.
 */
preg_match_all('/audit->(?:record|security)\((.*?)\);/s', $service, $auditCalls);

$leaky = array_values(array_filter(
    $auditCalls[1],
    static fn (string $args): bool => str_contains($args, 'token_hash')
        || str_contains($args, '$raw')
        || str_contains($args, 'rawToken')
        || str_contains($args, 'token_encrypted'),
));

$ok('no audit call carries a token value or digest', $leaky === []);

$ok(
    'the token is generated from a CSPRNG, not from anything about the person',
    str_contains($model, 'bin2hex(random_bytes(self::TOKEN_BYTES))')
        && str_contains($model, 'public const TOKEN_BYTES = 32;'),
);

/* ------------------------------------------------ retention */

echo "\n-- retention\n";

$policy = $read('app/Modules/Identity/Services/AbandonedAccountPolicy.php');

$ok(
    'an account reachable by password is never reclaimed',
    str_contains($policy, "'reason' => 'reachable_by_password'"),
);

$ok(
    'an account holding a live verification token is never reclaimed',
    str_contains($policy, "'reason' => 'verification_pending'")
        && str_contains($policy, 'TelegramVerificationToken::query()'),
);

$ok(
    'both refusals are decided BEFORE the retention window',
    strpos($policy, "'reachable_by_password'") < strpos($policy, "'within_retention'")
        && strpos($policy, "'verification_pending'") < strpos($policy, "'within_retention'"),
);

$contract = $read('app/Modules/Identity/Support/UserReferenceContract.php');

$ok(
    'the new table is classified in the user-reference contract',
    str_contains($contract, "'telegram_verification_tokens', 'user_id'"),
);

/* ------------------------------------------------ sign-in without Telegram */

echo "\n-- sign-in without Telegram\n";

$login = $read('app/Modules/Identity/Http/Controllers/Auth/AuthenticatedSessionController.php');

$ok(
    'the sign-in form accepts one identifier field',
    str_contains($login, "'login' => ['required', 'string', 'max:190'],")
        && ! str_contains($login, "'email' => ['required', 'string', 'email'],"),
);

$ok(
    'an email is distinguished by its shape, not by a fallback attempt',
    str_contains($login, "str_contains(\$identifier, '@')")
        && substr_count($login, 'Auth::attempt(') === 1,
);

$ok(
    'a phone identifier is matched through the blind index',
    str_contains($login, 'User::blindIndex(PhoneNumber::toE164($identifier))'),
);

$ok(
    'the throttle key is a digest, not the identifier itself',
    str_contains($login, "'login:id:'.hash('sha256', mb_strtolower(\$identifier))")
        && ! str_contains($login, "'login:email:'.mb_strtolower("),
);

/*
 * Matched on the ROUTE and the resolver, not on an exact argument list. The
 * first version pinned `localized_route('account.telegram.link')` with no
 * arguments, so adding the account's own locale — a fix the browser suite
 * demanded — broke the assertion while improving the behaviour it describes.
 *
 * The destination moved once more with the dual-method model: an unverified
 * sign-in now lands on the verification CHOICE page, where both doors —
 * Telegram and the WhatsApp code — are offered.
 */
$ok(
    'an unverified account is sent to verification rather than the admin dashboard',
    preg_match("/localized_route\('account\.verify'/", $login) === 1
        && preg_match('/PostLinkDestination::for\(\$user/', $login) === 1,
);

$ok(
    'the destination speaks the language the account registered in',
    str_contains($login, '$user->preferred_locale')
        && preg_match('/localized_route\(.*locale: \$locale\)/', $login) === 1,
);

$provider = $read('app/Modules/Identity/Providers/IdentityServiceProvider.php');

$ok(
    'the login rate limiter reads the same field the form posts',
    str_contains($provider, "\$request->input('login')")
        && ! str_contains($provider, "'login:email:'"),
);

/*
 * The registration form must set a password. Without one the account has no
 * credential at all and Telegram silently becomes the login mechanism again —
 * which is the behaviour this whole change exists to remove.
 */
$ok(
    'registration requires a password confirmed against the platform policy',
    str_contains($registration, "'password' => ['required', 'confirmed', PasswordRule::defaults()],"),
);

$ok(
    'the password reaches account creation',
    str_contains($registration, "'password' => \$validated['password'],")
        && str_contains($registrar, "'password' => \$data['password'],"),
);

$ok(
    'the password is never echoed back into the form on a refusal',
    str_contains($registration, "\$request->except(['phone', 'password', 'password_confirmation'])"),
);

/* ------------------------------------------------ the verification screen */

echo "\n-- the verification screen\n";

$page = $read('resources/js/Pages/Account/TelegramLink.vue');
$controller = $read('app/Modules/Identity/Http/Controllers/AccountTelegramLinkController.php');

$ok(
    'the page issues the permanent token and resumes it',
    str_contains($controller, '$rawToken = $this->verification->issueFor($user);'),
);

$ok(
    'the page no longer mints a ten-minute account-link intent',
    ! str_contains($controller, 'resumeOrBeginAccountLink('),
);

/*
 * Checked against CODE, not against the file text.
 *
 * The first version of this assertion matched the comment in TelegramLink.vue
 * that explains why the expiry countdown was removed — so the test failed on
 * its own documentation while the product was correct. Comments are stripped
 * before the check for exactly that reason.
 */
$stripComments = static function (string $source): string {
    $source = (string) preg_replace('#/\*.*?\*/#s', '', $source);
    $source = (string) preg_replace('#<!--.*?-->#s', '', $source);

    return (string) preg_replace('#^\s*//.*$#m', '', $source);
};

$ok(
    'no expiry is sent to the browser',
    ! str_contains($stripComments($controller), 'expires_in_seconds')
        && ! str_contains($stripComments($page), 'expires_in_seconds'),
);

$ok(
    'the screen says the link can be used later',
    str_contains($page, "t('identity.link.later')"),
);

$ok(
    'the screen offers a manual check as a fallback',
    str_contains($page, "t('identity.link.check_now')")
        && str_contains($page, 'setInterval(() => void poll(), 2500)'),
);

$ok(
    'the Telegram button is a real anchor so a mobile popup blocker cannot eat it',
    preg_match('/<a\s[^>]*:href="deep_link"[^>]*data-testid="open-telegram"/s', $page) === 1,
);

$ok(
    'the raw token is never rendered as text on the page',
    ! preg_match('/\{\{\s*(deep_link|token)\s*\}\}/', $page),
);

/* ------------------------------------------------ localisation */

echo "\n-- localisation\n";

$flatten = static function (array $rows, string $prefix = '') use (&$flatten): array {
    $out = [];

    foreach ($rows as $key => $value) {
        $out = is_array($value)
            ? array_merge($out, $flatten($value, $prefix.$key.'.'))
            : array_merge($out, [$prefix.$key]);
    }

    return $out;
};

$sets = [];

foreach (['en', 'ckb', 'ar'] as $locale) {
    $path = $root.'/lang/'.$locale.'/identity.php';
    $sets[$locale] = is_file($path) ? $flatten(require $path) : [];
}

foreach (['ckb', 'ar'] as $locale) {
    $ok(
        "{$locale} carries exactly the same identity keys as en",
        array_diff($sets['en'], $sets[$locale]) === []
            && array_diff($sets[$locale], $sets['en']) === [],
    );
}

/*
 * Every NEW key this change introduced, checked in all three languages. A key
 * present in en and missing elsewhere renders the raw key path to the person,
 * which is how a Sorani or Arabic screen ends up showing
 * "identity.link.press_start" in the middle of a sentence.
 */
foreach ([
    'auth.login_identifier',
    'auth.login_identifier_hint',
    'register.password_hint',
    'register.conflict_next_steps',
    'register.conflict_continue',
    'link.press_start',
    'link.later',
    'link.check_now',
    'telegram.bot_verified_account',
    'telegram.bot_already_connected',
    'onboarding.optional_title',
    'onboarding.optional_hint',
    'onboarding.city',
    'onboarding.city_none',
    'onboarding.area',
    'onboarding.area_none',
    'onboarding.location_unavailable',
    'onboarding.email',
    'onboarding.email_hint',
    'onboarding.gender',
    'onboarding.gender_unset',
    'onboarding.gender_female',
    'onboarding.gender_male',
    'onboarding.gender_undisclosed',
    'onboarding.date_of_birth',
    'onboarding.date_of_birth_hint',
] as $key) {
    $missing = array_values(array_filter(
        ['en', 'ckb', 'ar'],
        static fn (string $locale): bool => ! in_array($key, $sets[$locale], true),
    ));

    $ok("identity.{$key} exists in all three languages", $missing === []);
}

/*
 * The placeholder the server actually passes must be the one the string
 * expects. `:count` written as `:min` renders literally, and only in the
 * language nobody on the team reads.
 */
foreach (['en', 'ckb', 'ar'] as $locale) {
    $rows = require $root.'/lang/'.$locale.'/identity.php';

    $ok(
        "{$locale} password hint interpolates :count",
        str_contains((string) ($rows['register']['password_hint'] ?? ''), ':count'),
    );
}

/* ------------------------------------------------ optional profile fields */

echo "\n-- optional profile fields\n";

$onboarding = $read('app/Modules/Identity/Http/Controllers/Account/OnboardingController.php');
$profileMigration = $read('app/Modules/Identity/Database/Migrations/2026_08_09_000200_profile_optional_details.php');
$locationOptions = $read('app/Modules/Identity/Support/ProfileLocationOptions.php');

$ok(
    'gender and date of birth are nullable additions',
    str_contains($profileMigration, "\$table->string('gender', 16)->nullable()")
        && str_contains($profileMigration, "\$table->date('date_of_birth')->nullable()"),
);

$ok(
    'every new profile field is optional in validation',
    preg_match("/'email' => \[\s*'nullable'/", $onboarding) === 1
        && str_contains($onboarding, "'gender' => ['nullable'")
        && str_contains($onboarding, "'date_of_birth' => ['nullable'")
        && str_contains($onboarding, "'profile_area_id' => [\n                'nullable',"),
);

$ok(
    'the area must be a PUBLISHED area, matching what the picker offers',
    str_contains($onboarding, "Rule::exists('areas', 'id')->where('publication_status', 'published')"),
);

$ok(
    'a duplicate email is refused rather than reaching the unique index',
    str_contains($onboarding, "Rule::unique('users', 'email')->ignore(\$user->id)"),
);

$ok(
    'city and area come from the admin-managed table, with nothing hard-coded',
    str_contains($locationOptions, 'Area::query()')
        && str_contains($locationOptions, '->published()')
        && ! preg_match('/\'(Ankawa|Erbil|Hawler|Shaqlawa|Soran)\'/i', $locationOptions),
);

$ok(
    'no second city column was added — the city is derived from the hierarchy',
    ! str_contains($profileMigration, 'profile_city')
        && str_contains($locationOptions, 'public static function cityIdFor('),
);

/*
 * Scoped to the AUDIT CALL, not to the file.
 *
 * The first version searched the whole controller for `'email' => $user->email`
 * and flagged the Inertia payload, which legitimately sends the current address
 * back so the form can pre-fill it. Showing somebody their own email on their
 * own settings page is not a leak; writing it into the audit trail is, because
 * that is a second copy outside the retention policy.
 */
preg_match('/audit->record\(\'identity\.onboarding_completed\'.*?\]\);/s', $onboarding, $onboardingAudit);
$auditArgs = $onboardingAudit[0] ?? '';

$ok('the onboarding audit call is present', $auditArgs !== '');

$ok(
    'the audit record names which fields were filled, never their values',
    $auditArgs !== ''
        && str_contains($auditArgs, "'email_provided' => \$user->email !== null,")
        && ! preg_match("/'email'\s*=>\s*\\\$user->email\b(?!\s*!==)/", $auditArgs)
        && ! str_contains($auditArgs, '$user->date_of_birth,')
        && ! str_contains($auditArgs, '$user->gender,'),
);

/* ------------------------------------------------ the repaired loop */

echo "\n-- the repaired verification loop\n";

$authenticator = $read('app/Modules/Identity/Services/TelegramAuthenticator.php');

/*
 * Share-Contact used to set `telegram_id` without `telegram_verified_at`. The
 * gate reads the timestamp, so those accounts were refused everywhere personal
 * and bounced to a verification page that could not help them — a redirect loop
 * with no exit. All three branches must stamp it.
 */
preg_match('/private function applyVerifiedContact\(.*?\n    \}/s', $authenticator, $apply);
$applyBody = $apply[0] ?? '';

$ok('applyVerifiedContact is present', $applyBody !== '');

/*
 * Counted on the ASSIGNMENT, not on every mention of the column: two of the
 * three branches name it twice on one line (`=> $target->... ?? now()`), so a
 * raw substring count reads 5 and says nothing about how many branches were
 * covered.
 */
$ok(
    'every branch of applyVerifiedContact stamps telegram_verified_at',
    substr_count($applyBody, "'telegram_verified_at' =>") === 3,
);

$ok(
    'an already-verified account keeps its original timestamp',
    substr_count($applyBody, "'telegram_verified_at' => \$target->telegram_verified_at ?? now(),") === 2,
);

$ok(
    'redemption repairs an account holding an id with no timestamp',
    str_contains($service, 'if ($user->telegram_verified_at === null) {')
        && str_contains($service, "'via' => 'repaired_missing_timestamp'"),
);

/* ------------------------------------------------ the second door */

echo "\n-- the second door (WhatsApp OTP)\n";

$whatsappService = $read('app/Modules/Identity/Services/WhatsAppVerificationService.php');
$whatsappModel = $read('app/Modules/Identity/Models/WhatsAppOtp.php');
$whatsappMigration = $read('app/Modules/Identity/Database/Migrations/2026_08_19_000100_whatsapp_account_verification.php');
$gate = $read('app/Modules/Identity/Http/Middleware/EnsureTelegramLinked.php');
$userModel = $read('app/Modules/Identity/Models/User.php');
$returnController = $read('app/Modules/Identity/Http/Controllers/Auth/TelegramReturnController.php');
$abandonPolicy = $read('app/Modules/Identity/Services/AbandonedAccountPolicy.php');
$pruneCommand = $read('app/Modules/Identity/Console/PruneUnlinkedAccounts.php');

$ok('the WhatsApp service, model and migration exist', $whatsappService !== '' && $whatsappModel !== '' && $whatsappMigration !== '');

/*
 * THE INVARIANT THAT MAKES TWO DOORS SAFE: the WhatsApp flow never assigns a
 * Telegram identity column. Matched on the ASSIGNMENT shape, not on the
 * words — the docblock legitimately names the columns while explaining that
 * they are never written.
 */
$ok(
    'the WhatsApp service never writes a Telegram identity column',
    preg_match("/'telegram_(id|username|verified_at)'\s*=>/", $whatsappService) === 0,
);

$ok(
    'WhatsApp redemption stamps exactly its two claims',
    str_contains($whatsappService, "'whatsapp_verified_at' => now()")
        && str_contains($whatsappService, "'phone_verified' => true"),
);

$ok(
    'an already-verified account is resolved without a second stamp',
    str_contains($whatsappService, "'already_verified' => true")
        && str_contains($whatsappService, 'WhatsAppOtp::retireAllFor('),
);

$ok(
    'each side retires the other\'s pending material',
    str_contains($service, 'WhatsAppOtp::retireAllFor(')
        && str_contains($whatsappService, "revokeAllFor(\$fresh, 'verified_by_whatsapp')"),
);

$ok(
    'the Telegram redemption refuses to link an account WhatsApp already verified',
    str_contains($service, "'via' => 'account_already_verified'")
        && str_contains($service, "'reason' => 'already_verified'"),
);

$ok(
    'the personal-features gate honours either door through the one shared rule',
    str_contains($gate, 'hasVerifiedAccount()')
        && str_contains($userModel, 'public function hasVerifiedAccount(): bool')
        && str_contains($userModel, 'telegram_verified_at !== null')
        && str_contains($userModel, 'whatsapp_verified_at !== null'),
);

preg_match_all('/\$table->\w+\(\s*\'(\w+)\'/', $whatsappMigration, $otpColumns);

$ok(
    'the OTP table carries a clock, a consumption, a revocation and an attempts budget',
    in_array('expires_at', $otpColumns[1], true)
        && in_array('consumed_at', $otpColumns[1], true)
        && in_array('revoked_at', $otpColumns[1], true)
        && in_array('attempts', $otpColumns[1], true)
        && in_array('code_hash', $otpColumns[1], true)
        && in_array('phone_hash', $otpColumns[1], true),
);

$ok(
    'the code digest is KEYED, and a missing key refuses loudly',
    str_contains($whatsappModel, "hash_hmac('sha256', \$code, \$key)")
        && str_contains($whatsappModel, 'RuntimeException'),
);

/*
 * The Bird client must speak the RAW Channels API contract — template
 * referenced by projectId + version + locale with typed {type,key,value}
 * parameters — never the TypeScript SDK's slug/components convenience
 * surface, which does not map 1:1 onto the endpoint. Matched on the payload
 * ASSIGNMENTS (quoted keys), so the docblock explaining the SDK difference
 * cannot satisfy or trip it.
 */
$birdClient = $read('app/Modules/Identity/Services/BirdWhatsAppClient.php');

$ok(
    'the Bird client speaks the official Channels API template contract, not the SDK surface',
    str_contains($birdClient, "'projectId' =>")
        && str_contains($birdClient, "'version' =>")
        && str_contains($birdClient, "'locale' =>")
        && str_contains($birdClient, "'identifierValue' =>")
        && preg_match("/'type' => 'string',\s+'key' =>/", $birdClient) === 1
        && ! str_contains($birdClient, "'slug'")
        && ! str_contains($birdClient, "'components'"),
);

$ok(
    'only the documented 202 Accepted counts as a sent message',
    str_contains($birdClient, 'private const ACCEPTED = 202;')
        && str_contains($birdClient, '$response->status() !== self::ACCEPTED'),
);

$ok(
    'the model hides both digests',
    preg_match("/protected \\\$hidden = \\['code_hash', 'phone_hash'\\];/", $whatsappModel) === 1,
);

/* Audit calls must never carry the code or a digest. */
preg_match_all('/audit->(?:record|security)\((.*?)\);/s', $whatsappService, $whatsappAudits);

$leakyWhatsApp = array_values(array_filter(
    $whatsappAudits[1],
    static fn (string $args): bool => str_contains($args, 'code_hash')
        || str_contains($args, '$code')
        || str_contains($args, 'phone_hash'),
));

$ok('no WhatsApp audit call carries a code, a digest or a phone hash', $leakyWhatsApp === []);

$ok(
    'a WhatsApp-verified account is refused by reclamation and by self-abandon',
    str_contains($abandonPolicy, 'whatsapp_verified_at !== null')
        && str_contains($abandonPolicy, "'reason' => 'verified'"),
);

$ok(
    'the reclamation sweep never selects a WhatsApp-verified account',
    str_contains($pruneCommand, "whereNull('whatsapp_verified_at')"),
);

/*
 * The replay regression's server-side contract: every response on the return
 * route forbids STORING, so a browser can never replay the authenticated
 * redirect from cache or history — the exact mechanism behind "opening the
 * same return link again returned the user to the authenticated account".
 */
$ok(
    'the return handoff responses are never storable',
    str_contains($returnController, "'no-store, private'")
        && substr_count($returnController, 'self::CACHE_CONTROL') >= 4,
);

/* ------------------------------------------------ release inventory */

echo "\n-- release migration inventory\n";

/*
 * Final release run #33: the dual-verification release added a NINTH
 * migration above the sealed v6 baseline, and the deployment rehearsal still
 * asserted "exactly eight" — while its registration journey still expected
 * the pre-choice landing on the Telegram linking page. This suite runs inside
 * CI and inside the final release itself, so the inventory is pinned HERE:
 * every surface that states it must carry every name, and a post-baseline
 * migration missing from this list fails the suite before it can fail a
 * release. The count assertions stay fail-closed in the tooling — an
 * unexpected eleventh migration must fail exactly as the unexpected ninth
 * did. (The declared tenth is the price-record scope_id backfill, added with
 * the import scope-integrity fix and carried through every pinned surface.)
 */
$inventory = [
    'app/Modules/Identity/Database/Migrations/2026_08_06_000100_telegram_return_handoffs.php',
    'app/Modules/Identity/Database/Migrations/2026_08_09_000100_telegram_verification_tokens.php',
    'app/Modules/Identity/Database/Migrations/2026_08_09_000200_password_recovery_challenges.php',
    'app/Modules/Identity/Database/Migrations/2026_08_09_000200_profile_optional_details.php',
    'app/Modules/Identity/Database/Migrations/2026_08_09_000300_add_last_seen_to_users.php',
    'app/Modules/Knowledge/Database/Migrations/2026_08_16_000100_add_evidence_class_to_knowledge_events.php',
    'app/Modules/Knowledge/Database/Migrations/2026_08_17_000100_backfill_knowledge_event_search_keys.php',
    'app/Modules/Marketplace/Database/Migrations/2026_08_17_000200_backfill_offer_search_keys.php',
    'app/Modules/Identity/Database/Migrations/2026_08_19_000100_whatsapp_account_verification.php',
    'app/Modules/Market/Database/Migrations/2026_08_21_000100_backfill_price_record_scope_ids.php',
];

$allExist = true;

foreach ($inventory as $rel) {
    $allExist = $allExist && is_file($root.'/'.$rel);
}

$ok('every inventoried migration file exists in the tree', $allExist);

/*
 * The first post-baseline migration's date key. Any migration file at or
 * after it, in any migration directory, belongs to this release — and must
 * therefore be in the inventory above, in both rehearsals and in both
 * deployment documents, or it will arrive on production undeclared.
 */
$postBaseline = [];

foreach (array_merge(
    glob($root.'/app/Modules/*/Database/Migrations/*.php') ?: [],
    glob($root.'/database/migrations/*.php') ?: [],
) as $file) {
    if (basename($file) >= '2026_08_06') {
        $postBaseline[] = substr($file, strlen($root) + 1);
    }
}

sort($postBaseline);
$expectedInventory = $inventory;
sort($expectedInventory);

$ok('no post-baseline migration exists outside the inventory', $postBaseline === $expectedInventory);

$deployRehearsal = $read('scripts/release/deploy_rehearsal.sh');
$rollbackRehearsal = $read('scripts/release/rollback_rehearsal_v7.sh');

$namesEverywhere = static function (string $body) use ($inventory): bool {
    foreach ($inventory as $rel) {
        if (! str_contains($body, basename($rel, '.php'))) {
            return false;
        }
    }

    return true;
};

$ok(
    'the deployment rehearsal names every inventoried migration and pins the count fail-closed',
    $namesEverywhere($deployRehearsal)
        && str_contains($deployRehearsal, '[ "$DELTA" = "10" ]')
        && ! str_contains($deployRehearsal, '[ "$DELTA" = "9" ]'),
);

$ok(
    'the deployment rehearsal walks the choice-first registration journey',
    str_contains($deployRehearsal, '/ar/account/verify')
        && str_contains($deployRehearsal, 'Account/VerifyChoice')
        && str_contains($deployRehearsal, 'Account/TelegramLink')
        && str_contains($deployRehearsal, 'whatsapp_available'),
);

$ok(
    'the rollback rehearsal reverses the whole inventory by exact path and pins the count',
    $namesEverywhere($rollbackRehearsal)
        && str_contains($rollbackRehearsal, '[ "$PATCH_RAN" = "10" ]')
        && str_contains($rollbackRehearsal, 'RAN_BEFORE - 10')
        && str_contains($rollbackRehearsal, "'whatsapp_otps'")
        && str_contains($rollbackRehearsal, "'whatsapp_verified_at'"),
);

$ok(
    'both deployment documents name every inventoried migration',
    $namesEverywhere($read('DEPLOYMENT_NOTES.md'))
        && $namesEverywhere($read('ROLLBACK_NOTES.md')),
);

$ok(
    'the full-source builder requires the WhatsApp migration',
    str_contains(
        $read('scripts/release/build_full_source.py'),
        '2026_08_19_000100_whatsapp_account_verification.php',
    ),
);

echo "\n";

exit(TestTally::exitCode());
