<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Controllers\Admin;

use App\Modules\Content\Models\ContentItem;
use App\Modules\Operations\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Content administration (File one §10.1, §10.2).
 *
 * Publication is a transition, never a field an editor sets directly. §10.2
 * asks for approvals, and an editor who can write `status = published` has an
 * approval step that exists only in documentation — so `store` and `update`
 * accept content, and a separate, separately-permissioned action publishes it.
 *
 * Every mutation snapshots first. A revision taken after the change has already
 * lost the thing it was supposed to preserve.
 */
final class ContentController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'type' => ['nullable', Rule::in(ContentItem::TYPES)],
            'status' => ['nullable', 'string', 'max:24'],
        ]);

        $items = ContentItem::query()
            ->when($validated['type'] ?? null, fn ($q, $type) => $q->ofType($type))
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->with(['author:id,name'])
            ->latest('id')
            ->paginate(25)
            ->through(static fn (ContentItem $item): array => [
                'id' => $item->id,
                'type' => $item->type,
                'slug' => $item->slug,
                'title' => $item->title_ckb,
                'status' => $item->status,
                // Status and actual visibility are different questions, and an
                // editor needs both: an item can be 'published' and invisible
                // because its window has closed.
                'is_visible' => $item->isVisible(),
                'publish_at' => $item->publish_at?->toDateTimeString(),
                'unpublish_at' => $item->unpublish_at?->toDateTimeString(),
                'author' => $item->author?->name,
                'updated_at' => $item->updated_at?->toDateString(),
            ]);

        return Inertia::render('Admin/Content/Index', [
            'items' => $items,
            'types' => ContentItem::TYPES,
            'filters' => $validated,
            'can' => [
                'publish' => $request->user()?->hasPermission('content.publish') ?? false,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /**
         * Declaring the key type makes the analyser check every validated key
         * against ContentItem's real columns, so a rule added for a field the model
         * does not have fails here rather than silently never persisting.
         *
         * @var array<model-property<ContentItem>, mixed> $validated
         */
        $validated = $this->validated($request);

        $item = ContentItem::query()->create($validated + [
            'author_id' => $request->user()?->id,
            // Never publishable on creation. Publication is its own action with
            // its own permission.
            'status' => 'draft',
        ]);

        $item->snapshot($request->user()?->id, 'created');
        $this->audit->record('content.created', $item, $validated);

        return back()->with('success', __('content.saved'));
    }

    public function update(Request $request, ContentItem $content): RedirectResponse
    {
        $validated = $this->validated($request, $content->id);

        // Snapshot BEFORE the change: a revision taken afterwards has already
        // lost what it was meant to preserve.
        $content->snapshot($request->user()?->id, 'edited');

        $content->fill($validated);
        $content->save();

        $this->audit->recordModelChange('content.updated', $content);

        return back()->with('success', __('content.saved'));
    }

    /**
     * Publish, with its own permission.
     *
     * `published_at` is stamped only on the first publication. Re-publishing
     * after an edit must not rewrite the original date — the date is a claim
     * about when this was first said, and readers use it to judge whether
     * analysis is current.
     */
    public function publish(Request $request, ContentItem $content): RedirectResponse
    {
        $content->snapshot($request->user()?->id, 'published');

        $content->forceFill([
            'status' => 'published',
            'reviewed_by' => $request->user()?->id,
            'published_at' => $content->published_at ?? now(),
        ])->save();

        $this->audit->record('content.published', $content, [], [
            'actor_id' => $request->user()?->id,
        ]);

        return back()->with('success', __('content.published'));
    }

    public function unpublish(Request $request, ContentItem $content): RedirectResponse
    {
        $content->snapshot($request->user()?->id, 'unpublished');
        $content->forceFill(['status' => 'draft'])->save();

        $this->audit->record('content.unpublished', $content, [], [
            'actor_id' => $request->user()?->id,
        ], severity: 'warning');

        return back()->with('success', __('content.unpublished'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'type' => ['required', Rule::in(ContentItem::TYPES)],
            'slug' => [
                'required', 'string', 'max:191', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('content_items', 'slug')->ignore($ignoreId),
            ],
            // ckb is required and the others are not: this is a Sorani-first
            // platform, and an item with only an English title is not publishable
            // content here.
            'title_ckb' => ['required', 'string', 'max:191'],
            'title_ar' => ['nullable', 'string', 'max:191'],
            'title_en' => ['nullable', 'string', 'max:191'],
            'body_ckb' => ['nullable', 'string'],
            'body_ar' => ['nullable', 'string'],
            'body_en' => ['nullable', 'string'],
            'excerpt_ckb' => ['nullable', 'string', 'max:255'],
            'excerpt_ar' => ['nullable', 'string', 'max:255'],
            'excerpt_en' => ['nullable', 'string', 'max:255'],
            'video_url' => ['nullable', 'url', 'max:255'],
            'seo_title' => ['nullable', 'string', 'max:191'],
            'seo_description' => ['nullable', 'string', 'max:255'],
            'seo_indexable' => ['nullable', 'boolean'],
            'publish_at' => ['nullable', 'date'],
            // §10.2 expiry. Must be after the opening, or the window can never
            // be open and the item silently never appears.
            'unpublish_at' => ['nullable', 'date', 'after:publish_at'],
            'related_entity_ids' => ['nullable', 'array'],
        ]);
    }
}
