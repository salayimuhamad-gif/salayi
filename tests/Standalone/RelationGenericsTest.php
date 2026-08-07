<?php

declare(strict_types=1);

/*
 * Fixture tests for the token-aware relation/scope annotator.
 *
 * Two earlier text-based implementations corrupted source files, and a third
 * reported seven scopes as unannotated when their docblocks were in fact
 * correct — a regex "find the preceding comment" that matched an unrelated one.
 * Every case below is one of the shapes those versions got wrong.
 */

require __DIR__.'/../../scripts/support/RelationGenerics.php';
require_once __DIR__.'/../../scripts/support/TestTally.php';

use Mulkihawler\Tooling\RelationGenerics;
use Mulkihawler\Tooling\TestTally;

$engine = new RelationGenerics;
TestTally::reset();

function check(string $name, bool $ok, string $detail = ''): void
{
    if ($ok) {
        echo "  pass {$name}\n";

        return;
    }

    TestTally::fail();
    echo "  FAIL {$name}\n";

    if ($detail !== '') {
        echo '        '.str_replace("\n", "\n        ", $detail)."\n";
    }
}

/** Wrap a class body in a compilable file. */
function model(string $body, string $class = 'Thing'): string
{
    return "<?php\n\nnamespace App\\Modules\\Demo\\Models;\n\n"
        ."use App\\Modules\\Other\\Models\\Owner;\n"
        ."use Illuminate\\Database\\Eloquent\\Builder;\n"
        ."use Illuminate\\Database\\Eloquent\\Model;\n"
        ."use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;\n"
        ."use Illuminate\\Database\\Eloquent\\Relations\\HasMany;\n\n"
        ."class {$class} extends Model\n{\n".$body."}\n";
}

$belongsTo = <<<'PHP'
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }
PHP;

/* 1. method with no docblock -------------------------------------------- */
$src = model($belongsTo."\n");
$out = (string) $engine->propose($src);
check('1 no docblock: generics inserted',
    str_contains($out, '@return BelongsTo<Owner, $this>'), $out);
check('1 output parses', $engine->isParseable($out));

/* 2. prose-only docblock is preserved ------------------------------------ */
$src = model("    /**\n     * The company that owns this, per spec 12.4.\n     */\n".$belongsTo."\n");
$out = (string) $engine->propose($src);
check('2 prose kept',
    str_contains($out, 'The company that owns this, per spec 12.4.')
    && str_contains($out, '@return BelongsTo<Owner, $this>'), $out);

/* 3. existing INCOMPLETE generic is corrected ----------------------------- */
$src = model("    /** @return BelongsTo */\n".$belongsTo."\n");
$out = (string) $engine->propose($src);
check('3 incomplete generic corrected',
    str_contains($out, '@return BelongsTo<Owner, $this>'), $out);

/* 4. existing CORRECT generic is left alone ------------------------------ */
$src = model("    /**\n     * @return BelongsTo<Owner, \$this>\n     */\n".$belongsTo."\n");
check('4 correct generic is a no-op', $engine->propose($src) === null);
check('4 correct generic reports no problem', $engine->problems($src, 'f') === []);

/* 5. two accidentally adjacent docblocks --------------------------------- */
$src = model("    /**\n     * Real prose.\n     */\n    /**\n     * @return BelongsTo\n     */\n".$belongsTo."\n");
$out = (string) $engine->propose($src);
check('5 adjacent docblocks do not multiply',
    substr_count($out, '/**') === 1 && str_contains($out, '@return BelongsTo<Owner, $this>'), $out);

/* 6. an attribute between documentation and the method ------------------- */
$src = model("    /**\n     * Documented.\n     */\n    #[\\Deprecated]\n".$belongsTo."\n");
$out = (string) $engine->propose($src);
check('6 attribute does not hide the docblock',
    substr_count($out, '/**') === 1
    && str_contains($out, 'Documented.')
    && str_contains($out, '@return BelongsTo<Owner, $this>'), $out);
check('6 output parses', $engine->isParseable($out));

/* 7. an UNRELATED preceding comment is not treated as the docblock ------- */
$src = model(
    "    /**\n     * A property, documented.\n     */\n    protected \$fillable = ['a'];\n\n".$belongsTo."\n"
);
$out = (string) $engine->propose($src);
check('7 unrelated docblock untouched',
    str_contains($out, 'A property, documented.')
    && substr_count($out, '/**') === 2
    && str_contains($out, '@return BelongsTo<Owner, $this>'), $out);

/* 8. imported aliases compare equal to fully qualified names -------------- */
$src = model("    /**\n     * @return BelongsTo<\\App\\Modules\\Other\\Models\\Owner, \$this>\n     */\n".$belongsTo."\n");
check('8 alias and FQCN compare equal', $engine->problems($src, 'f') === []);

/* 9. self, static and $this all mean the declaring side ------------------ */
foreach (['self', 'static', '$this'] as $spelling) {
    $src = model("    /**\n     * @return BelongsTo<Owner, {$spelling}>\n     */\n".$belongsTo."\n");
    check("9 declaring side accepts {$spelling}", $engine->problems($src, 'f') === []);
}

/* 10. a genuinely WRONG related model must still fail -------------------- */
$src = model("    /**\n     * @return BelongsTo<\\App\\Wrong\\Model, \$this>\n     */\n".$belongsTo."\n");
check('10 wrong related model is reported', $engine->problems($src, 'f') !== []);

/* 10b. a bare morphTo() has no class argument ---------------------------- */
$morph = <<<'PHP'
<?php
namespace App\Demo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Subject extends Model
{
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
PHP;

$out = (string) $engine->propose($morph);
check('10b bare morphTo() is annotated',
    str_contains($out, '@return MorphTo<\Illuminate\Database\Eloquent\Model, $this>'), $out);
check('10b morphTo output parses', $engine->isParseable($out));
check('10b morphTo is a no-op on the second run', $engine->propose($out) === null);

/* 11. idempotence -------------------------------------------------------- */
$src = model($belongsTo."\n");
$once = (string) $engine->propose($src);
check('11 second run proposes nothing', $engine->propose($once) === null);

/* 12. scopes: prose kept, generics added, other tags preserved ----------- */
$scope = <<<'PHP'
    /**
     * Only rows that may serve.
     *
     * @throws \RuntimeException
     */
    public function scopeServable(Builder $query): Builder
    {
        return $query;
    }
PHP;
$src = model($scope."\n");
$out = (string) $engine->propose($src);
check('12 scope prose kept',
    str_contains($out, 'Only rows that may serve.'), $out);
check('12 scope @throws preserved', str_contains($out, '@throws \RuntimeException'), $out);
check('12 scope generics added',
    str_contains($out, '@param  Builder<Thing>  $query')
    && str_contains($out, '@return Builder<Thing>'), $out);
check('12 scope output parses', $engine->isParseable($out));

/* 13. transactional: one bad proposal writes nothing ---------------------- */
$tmp = sys_get_temp_dir().'/relgen-tx-'.bin2hex(random_bytes(4)).'.php';
file_put_contents($tmp, model($belongsTo."\n"));
$before = (string) file_get_contents($tmp);

try {
    $engine->commit([$tmp => '<?php this is not php {']);
    check('13 invalid proposal is rejected', false, 'commit() accepted unparseable output');
} catch (RuntimeException $e) {
    check('13 invalid proposal is rejected', true);
}

check('13 no file was written', (string) file_get_contents($tmp) === $before);
@unlink($tmp);

echo TestTally::failures() === 0
    ? "\nALL RELATION GENERICS ASSERTIONS PASSED\n"
    : "\n".TestTally::failures()." RELATION GENERICS FAILURES\n";

exit(TestTally::exitCode());
