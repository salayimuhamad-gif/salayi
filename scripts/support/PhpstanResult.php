<?php

declare(strict_types=1);

namespace Mulkihawler\Tooling;

use JsonException;

/**
 * Read a PHPStan JSON result, refusing to report a count it cannot trust.
 *
 * A previous measurement reported "0 findings" when PHPStan had in fact aborted
 * during Larastan's application bootstrap and written an empty file. Zero is
 * the single most dangerous wrong answer a static-analysis counter can give: it
 * looks like success, it ends the work, and it would have put a false claim
 * into the release documentation.
 *
 * Every failure mode therefore produces MEASUREMENT INVALID and a non-zero exit
 * — never a number.
 */
final class PhpstanResult
{
    private function __construct(
        public readonly bool $valid,
        public readonly int $total,
        /** @var array<string, int> */
        public readonly array $byIdentifier,
        public readonly string $reason,
    ) {}

    /**
     * @param  int  $exitCode  the analyser's process exit code
     * @param  string|null  $json  raw JSON, or null when the file is missing
     * @param  string  $stderr  captured stderr, kept for the failure message
     */
    public static function parse(int $exitCode, ?string $json, string $stderr = '', string $command = ''): self
    {
        $invalid = static fn (string $why): self => new self(false, -1, [], $why
            .($command === '' ? '' : "\n  command: ".$command)
            .($stderr === '' ? '' : "\n  stderr: ".trim($stderr)));

        if ($json === null) {
            return $invalid('the result file is missing.');
        }

        if (trim($json) === '') {
            return $invalid('the result file is empty, which usually means the analyser aborted.');
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return $invalid('the result file is not valid JSON ('.$e->getMessage().').');
        }

        if (! is_array($decoded) || ! array_key_exists('files', $decoded)) {
            return $invalid('the result JSON has no "files" key; it is truncated or not a PHPStan report.');
        }

        /*
         * `errors` holds analyser-level failures — bootstrap crashes, missing
         * autoload, an internal error — which are NOT per-file findings and do
         * not appear in `files`. Counting only `files` is exactly how an
         * aborted run reads as zero.
         */
        $analyserErrors = is_array($decoded['errors'] ?? null) ? $decoded['errors'] : [];

        if ($analyserErrors !== []) {
            return $invalid('the analyser reported '.count($analyserErrors)
                .' severe error(s), so the result is incomplete: '
                .substr((string) ($analyserErrors[0] ?? ''), 0, 200));
        }

        $byIdentifier = [];
        $total = 0;

        /** @var array<string, array{messages?: array<int, array<string, mixed>>}> $files */
        $files = is_array($decoded['files']) ? $decoded['files'] : [];

        foreach ($files as $file) {
            foreach ($file['messages'] ?? [] as $message) {
                $total++;
                $identifier = (string) ($message['identifier'] ?? 'none');
                $byIdentifier[$identifier] = ($byIdentifier[$identifier] ?? 0) + 1;
            }
        }

        /*
         * A clean run exits 0. A non-zero exit with no countable findings means
         * the analyser failed for a reason it did not put in the report, so the
         * measurement is refused rather than reported as zero.
         */
        if ($exitCode !== 0 && $total === 0) {
            return $invalid('the analyser exited '.$exitCode.' but reported no findings; the run did not complete.');
        }

        // A zero exit must agree with a zero count, or the report is stale.
        if ($exitCode === 0 && $total > 0) {
            return $invalid('the analyser exited 0 while the report contains '
                .$total.' finding(s); the result file does not match the run.');
        }

        arsort($byIdentifier);

        return new self(true, $total, $byIdentifier, '');
    }

    public function describe(): string
    {
        if (! $this->valid) {
            return "MEASUREMENT INVALID\n  ".$this->reason;
        }

        $lines = ['PHPStan findings: '.$this->total];

        foreach ($this->byIdentifier as $identifier => $count) {
            $lines[] = sprintf('  %4d  %s', $count, $identifier);
        }

        return implode("\n", $lines);
    }
}
