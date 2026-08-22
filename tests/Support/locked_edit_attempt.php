<?php

declare(strict_types=1);

/*
 * Child process for ValuationSerializationConcurrencyTest: a REAL second
 * database connection — its own PDO handle in its own process — attempting
 * a serialized draft edit against a set the parent process may be
 * publishing at the same moment. Prints exactly one JSON line describing
 * what happened, so the parent can assert the serialization contract from
 * genuinely concurrent evidence rather than a same-connection simulation.
 *
 * argv: [1] rule-set id, [2] option id to edit, [3] marker file touched
 * immediately before the edit attempt (the parent holds its lock open
 * until this marker exists, so the attempt provably starts while the
 * publish transaction is still in flight).
 */

use App\Modules\Portfolio\Exceptions\ValuationRulePublishException;
use App\Modules\Portfolio\Models\ValuationQuestionOption;
use App\Modules\Portfolio\Models\ValuationRuleSet;
use App\Modules\Portfolio\Services\ValuationRuleEditor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = $_SERVER['argv'] ?? [];
$setId = (int) ($args[1] ?? 0);
$optionId = (int) ($args[2] ?? 0);
$marker = (string) ($args[3] ?? '');

/*
 * On SQLite there is no row lock to wait on — contention surfaces as the
 * database-level busy state instead. A short busy timeout makes this
 * process the deterministic loser of a standoff with the parent (whose
 * timeout is longer), so the test resolves in about a second instead of
 * the driver's default.
 */
if (DB::connection()->getDriverName() === 'sqlite') {
    DB::statement('PRAGMA busy_timeout = 800');
}

if ($marker !== '') {
    touch($marker);
}

$start = microtime(true);
$result = ['outcome' => 'wrote'];

try {
    $editor = app(ValuationRuleEditor::class);
    $editor->withLockedDraft($setId, static function (ValuationRuleSet $locked) use ($optionId): void {
        $option = ValuationQuestionOption::query()->findOrFail($optionId);
        $option->adjustment_percent = '30.000';
        $option->save();
    });
} catch (ValuationRulePublishException $e) {
    $result = ['outcome' => 'refused', 'error' => $e->errorKey];
} catch (Throwable $e) {
    $result = ['outcome' => 'error', 'class' => get_class($e), 'message' => mb_substr($e->getMessage(), 0, 160)];
}

$result['elapsed_ms'] = (int) round((microtime(true) - $start) * 1000);

echo json_encode($result), PHP_EOL;
