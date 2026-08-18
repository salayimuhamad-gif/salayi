<?php

declare(strict_types=1);

namespace App\Modules\Advisor\Services;

use App\Modules\Advisor\Support\RetrievalGuard;
use App\Modules\Geography\Models\Area;
use App\Modules\Knowledge\Models\KnowledgeEvent;
use App\Modules\Projects\Models\Project;
use Illuminate\Support\Collection;

/**
 * Deterministic market-intelligence retrieval (Phase 12).
 *
 * The advisor's market answers come from admin-authored KnowledgeEvents and
 * nowhere else. Retrieval is plain relational SQL — no embeddings, no
 * similarity search — because a closed, reviewed data set is matched by the
 * relationships the admins actually recorded, not by what a vector thinks
 * "looks related".
 *
 * Two gates, in series:
 *
 *   1. `KnowledgeEvent::aiUsable()` (the scope) — approved/published AND
 *      per-event AI permission AND unexpired. Nothing outside it is even
 *      loaded, so a draft or a rejected note cannot reach a public reply by
 *      construction.
 *   2. {@see RetrievalGuard} — the spec-17.3 allowlist, consumed through its
 *      existing `knowledge_event` entry. The scope already proves an
 *      approved-level review happened (Published sits BEYOND Approved in the
 *      workflow), so admitted events pass state `approved`; the guard
 *      re-checks expiry independently. Belt and braces, per the guard's own
 *      philosophy that each layer assumes the other can be wrong.
 *
 * Scope resolution is deliberately narrow: the areas the person named (in
 * their interview criteria or in the question itself), plus the recommended
 * projects on screen. Only when the person expressed NO area preference at
 * all does it widen to the areas of those recommended projects — never
 * beyond. All KnowledgeEvents are never dumped into a prompt.
 */
final class AdvisorInsightRetriever
{
    /**
     * At most this many insights inform one answer. Recency-ordered, so the
     * cap keeps the freshest evidence; a market answer citing more than a
     * handful of events stops being an answer.
     */
    private const LIMIT = 5;

    /** Mirrors AdvisorProjectMatcher::areaPreference() — "no preference"
     * phrasings and the whole-city name are not an area scope. */
    private const NO_PREFERENCE = [
        '', 'مو مهم', 'ما عندي منطقة محددة', 'ما عندي منطقه محدده',
        'not important', 'no area preference', 'گرنگ نییە',
        'ناوچەی دیاریکراوم نییە', 'erbil', 'hawler', 'اربيل', 'أربيل', 'هەولێر',
    ];

    public function __construct(private readonly RetrievalGuard $guard) {}

    /**
     * @param  array<string, mixed>  $criteria  the conversation criteria (area is free text)
     * @param  string  $userText  the market question as typed
     * @param  array<string, mixed>|null  $recommendations  the standing recommendation payload
     * @return list<KnowledgeEvent> newest-first, at most LIMIT, every row AI-usable and guard-admitted
     */
    public function retrieve(array $criteria, string $userText, ?array $recommendations): array
    {
        $projectIds = $this->recommendedProjectIds($recommendations);
        $areaIds = $this->matchedAreaIds($criteria, $userText, $projectIds);

        if ($areaIds === [] && $projectIds === []) {
            return [];
        }

        $events = KnowledgeEvent::query()
            ->aiUsable()
            ->where(function ($query) use ($areaIds, $projectIds): void {
                if ($areaIds !== []) {
                    $query->orWhere(function ($q) use ($areaIds): void {
                        $q->where('entity_type', 'area')->whereIn('entity_id', $areaIds);
                    });
                }

                if ($projectIds !== []) {
                    $query->orWhere(function ($q) use ($projectIds): void {
                        $q->where('entity_type', 'project')->whereIn('entity_id', $projectIds);
                    });
                }
            })
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        return $this->admitted($events);
    }

    /**
     * The spec-17.3 allowlist, applied per event WITHOUT modifying the guard:
     * the aiUsable scope proves approved-level review (Published is reachable
     * only THROUGH Approved), so each event presents state `approved` — the
     * state the guard's existing `knowledge_event` entry requires — and its
     * own expiry date for the guard's independent expiry check.
     *
     * @param  Collection<int, KnowledgeEvent>  $events
     * @return list<KnowledgeEvent>
     */
    private function admitted(Collection $events): array
    {
        $kept = [];

        foreach ($events as $event) {
            $verdict = $this->guard->admit([
                'type' => 'knowledge_event',
                'state' => 'approved',
                'expires_at' => $event->expires_on?->toDateString(),
            ]);

            if ($verdict['allowed']) {
                $kept[] = $event;
            }
        }

        return $kept;
    }

    /**
     * The areas this conversation is actually about.
     *
     * Candidates are only areas that HAVE ai-usable events — the set is tiny,
     * so name matching happens in PHP with the same `sorani_search_key`
     * folding the project matcher uses. A candidate matches when:
     *
     *   - the criteria area text and the area name contain one another
     *     (bidirectional, exactly like AdvisorProjectMatcher::areaMatches), or
     *   - the area's folded name appears inside the folded question text
     *     ("why did prices rise in Ankawa?" names the area directly).
     *
     * With no explicit preference and no area named in the question, the
     * recommended projects' own areas stand in — the person is implicitly
     * asking about the market where their recommendations are. An explicit
     * preference that matches nothing stays unmatched: it must NOT widen to
     * other areas, or an insight about somewhere else would answer a question
     * about the place the person actually named.
     *
     * @param  array<string, mixed>  $criteria
     * @param  list<int>  $projectIds
     * @return list<int>
     */
    private function matchedAreaIds(array $criteria, string $userText, array $projectIds): array
    {
        $candidateIds = KnowledgeEvent::query()
            ->aiUsable()
            ->where('entity_type', 'area')
            ->whereNotNull('entity_id')
            ->distinct()
            ->pluck('entity_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($candidateIds === []) {
            return [];
        }

        $preference = $this->areaPreference($criteria['area'] ?? null);
        $questionKey = sorani_search_key($userText);
        $preferenceKey = $preference === null ? '' : sorani_search_key($preference);

        $matched = [];

        foreach (Area::query()->whereIn('id', $candidateIds)->get(['id', 'name_ckb', 'name_ar', 'name_en', 'search_key']) as $area) {
            $names = array_filter([
                sorani_search_key($area->name_ckb),
                sorani_search_key($area->name_ar),
                sorani_search_key($area->name_en),
                sorani_search_key($area->search_key),
            ], static fn (string $key): bool => $key !== '');

            foreach ($names as $name) {
                $byPreference = $preferenceKey !== ''
                    && (str_contains($name, $preferenceKey) || str_contains($preferenceKey, $name));
                $byQuestion = $questionKey !== '' && str_contains($questionKey, $name);

                if ($byPreference || $byQuestion) {
                    $matched[] = (int) $area->id;

                    break;
                }
            }
        }

        if ($matched !== [] || $preference !== null) {
            return array_values(array_unique($matched));
        }

        // No preference, no area named: the recommendations' own areas.
        if ($projectIds === []) {
            return [];
        }

        return Project::query()
            ->whereIn('id', $projectIds)
            ->whereNotNull('area_id')
            ->pluck('area_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>|null  $recommendations
     * @return list<int>
     */
    private function recommendedProjectIds(?array $recommendations): array
    {
        $ids = [];

        foreach ((array) ($recommendations['items'] ?? []) as $item) {
            if (is_array($item) && is_numeric($item['id'] ?? null)) {
                $ids[] = (int) $item['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /** Mirrors the matcher: a "no preference" answer is not an area scope. */
    private function areaPreference(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);
        $normalised = sorani_search_key($text);

        foreach (self::NO_PREFERENCE as $item) {
            if ($normalised === sorani_search_key($item)) {
                return null;
            }
        }

        return $text;
    }
}
