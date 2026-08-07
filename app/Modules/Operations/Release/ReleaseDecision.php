<?php

declare(strict_types=1);

namespace App\Modules\Operations\Release;

use InvalidArgumentException;

/**
 * The production release decision (Appendix E).
 *
 * Appendix E is unusually prescriptive: the decision must contain EXACTLY ONE
 * final state, and if it is not ready every blocker must be classified into one
 * of ten categories. This class exists so that verdict is computed from
 * evidence rather than asserted by whoever is writing the release note.
 *
 * The design commitment: `NOT READY` is the default and `READY` must be earned.
 * There is no path that returns READY with an unresolved blocker, and no
 * severity below which a blocker stops counting. A release decision that can be
 * talked into READY is not a decision.
 *
 * Warnings exist separately and do NOT block. The distinction matters: an
 * advisory memory_limit is worth surfacing and is not worth stopping a release
 * for, and conflating the two trains people to ignore blockers.
 */
final class ReleaseDecision
{
    public const READY = 'READY FOR PRODUCTION';

    public const NOT_READY = 'NOT READY FOR PRODUCTION';

    /** The ten blocker categories, Appendix E, in the order it lists them. */
    public const CATEGORIES = [
        'code', 'database', 'infrastructure', 'integration', 'data',
        'security', 'performance', 'language', 'legal', 'business_approval',
    ];

    /** @var list<array{category: string, summary: string, evidence: string|null}> */
    private array $blockers = [];

    /** @var list<array{category: string, summary: string}> */
    private array $warnings = [];

    /** @var list<array{check: string, passed: bool, detail: string|null}> */
    private array $checks = [];

    public function blocker(string $category, string $summary, ?string $evidence = null): self
    {
        if (! in_array($category, self::CATEGORIES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown blocker category "%s". Appendix E defines exactly: %s.',
                $category,
                implode(', ', self::CATEGORIES),
            ));
        }

        $this->blockers[] = ['category' => $category, 'summary' => $summary, 'evidence' => $evidence];

        return $this;
    }

    public function warning(string $category, string $summary): self
    {
        if (! in_array($category, self::CATEGORIES, true)) {
            throw new InvalidArgumentException(sprintf('Unknown warning category "%s".', $category));
        }

        $this->warnings[] = ['category' => $category, 'summary' => $summary];

        return $this;
    }

    /**
     * Record a gate result. A failed gate becomes a blocker automatically, so a
     * check cannot be recorded as failed and then quietly not block.
     */
    public function check(string $name, bool $passed, string $category, ?string $detail = null): self
    {
        $this->checks[] = ['check' => $name, 'passed' => $passed, 'detail' => $detail];

        if (! $passed) {
            $this->blocker($category, $name, $detail);
        }

        return $this;
    }

    /**
     * @return array{
     *     state: string, ready: bool,
     *     blockers: list<array{category: string, summary: string, evidence: string|null}>,
     *     blockers_by_category: array<string, int>,
     *     warnings: list<array{category: string, summary: string}>,
     *     checks: list<array{check: string, passed: bool, detail: string|null}>,
     *     checks_passed: int, checks_total: int, decided_at: string
     * }
     */
    public function decide(?string $decidedAt = null): array
    {
        $byCategory = [];

        foreach ($this->blockers as $blocker) {
            $byCategory[$blocker['category']] = ($byCategory[$blocker['category']] ?? 0) + 1;
        }

        ksort($byCategory);

        $passed = count(array_filter($this->checks, static fn (array $c): bool => $c['passed']));

        return [
            // Exactly one of two states, Appendix E.
            'state' => $this->blockers === [] ? self::READY : self::NOT_READY,
            'ready' => $this->blockers === [],
            'blockers' => $this->blockers,
            'blockers_by_category' => $byCategory,
            'warnings' => $this->warnings,
            'checks' => $this->checks,
            'checks_passed' => $passed,
            'checks_total' => count($this->checks),
            'decided_at' => $decidedAt ?? date('c'),
        ];
    }

    /** @param array<string, mixed> $decision */
    public static function render(array $decision): string
    {
        $lines = [
            '# Production release decision',
            '',
            '**'.$decision['state'].'**',
            '',
            sprintf('Gates: %d of %d passed.', $decision['checks_passed'], $decision['checks_total']),
            '',
        ];

        if ($decision['blockers'] !== []) {
            $lines[] = '## Blockers';
            $lines[] = '';

            foreach (self::CATEGORIES as $category) {
                $inCategory = array_values(array_filter(
                    $decision['blockers'],
                    static fn (array $b): bool => $b['category'] === $category,
                ));

                if ($inCategory === []) {
                    continue;
                }

                $lines[] = '### '.ucwords(str_replace('_', ' ', $category));
                $lines[] = '';

                foreach ($inCategory as $blocker) {
                    $lines[] = '- '.$blocker['summary']
                        .($blocker['evidence'] !== null ? ' — '.$blocker['evidence'] : '');
                }

                $lines[] = '';
            }
        }

        if ($decision['warnings'] !== []) {
            $lines[] = '## Warnings (non-blocking)';
            $lines[] = '';

            foreach ($decision['warnings'] as $warning) {
                $lines[] = '- ['.$warning['category'].'] '.$warning['summary'];
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}
