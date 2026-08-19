<?php

/*
 * A FIXTURE, not application code: it exists to be parsed by SignatureGuard.
 * Wrapped in a class so it is valid PHP in its own right — `$this` outside a
 * class is not something the analyser should have to tolerate, and the guard
 * reads the artisan call either way.
 */
final class FixtureCorrectUsage
{
    /** @param  array<string, mixed>  $parameters */
    public function artisan(string $command, array $parameters = []): self
    {
        return $this;
    }

    /** @return list<mixed> every call's result, so none is discarded */
    public function run(): array
    {
        $service = app(\Fixtures\SignatureGuard\Subject::class);
        $r0 = $service->finalise(1, 'public', 'offers/1/a.jpg', 42);
        $r1 = $service->onlyTwo(1);
        $r2 = $service->onlyTwo(a: 1, b: 2);
        $r3 = $service->anyNumber(1, 2, 3, 4, 5);
        $r4 = \Fixtures\SignatureGuard\Subject::build('x');
        $known = \Fixtures\SignatureGuard\Subject::KNOWN;
        $r5 = $this->artisan('mulkihawler:fixture --dry-run --limit=10 --tag=a');

        return [$r0, $r1, $r2, $r3, $r4, $r5];
    }
}