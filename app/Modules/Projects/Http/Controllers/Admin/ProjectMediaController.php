<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers\Admin;

use App\Modules\Core\Support\MediaUploader;
use App\Modules\Projects\Exceptions\DuplicateMediaException;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectMedia;
use App\Modules\Projects\Services\ProjectMediaService;
use App\Modules\Projects\Support\ProjectScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Project media (spec 12.1).
 */
final class ProjectMediaController extends Controller
{
    public function __construct(
        private readonly ProjectMediaService $media,
        private readonly MediaUploader $uploader,
    ) {}

    public function index(Request $request, Project $project): Response
    {
        // Media is project data: reaching it must obey the same scope the
        // project itself does.
        ProjectScope::authorise($request, $project);

        return Inertia::render('Admin/Projects/Media', [
            'project' => ['id' => $project->id, 'name' => $project->name()],
            'media' => $this->collection($project),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        ProjectScope::authorise($request, $project);

        /*
         * CAPTURED, not discarded.
         *
         * The validated array and the uploaded file were both dropped on the
         * floor and then referenced further down — every final media upload
         * threw. All three alt locales are accepted here too: a Sorani
         * description silently unvalidated is a Sorani description silently
         * lost.
         */
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:'.(int) config('filesystems.uploads.max_image_kb')],
            'alt_ckb' => ['nullable', 'string', 'max:191'],
            'alt_ar' => ['nullable', 'string', 'max:191'],
            'alt_en' => ['nullable', 'string', 'max:191'],
            'credit' => ['nullable', 'string', 'max:191'],
        ]);

        $file = $request->file('file');

        $result = $this->uploader->storeImage($file, 'projects/'.$project->id);

        if (! $result['ok']) {
            return back()->withErrors(['file' => __('media.errors.'.$result['reason'])]);
        }

        /*
         * The duplicate check lives in the service, under the row lock. Doing
         * it here as well was a second, unlocked implementation: two
         * concurrent uploads both saw "no duplicate" and both proceeded.
         */

        try {
            $media = $this->media->storeForProject($project->id, $result, [
                'kind' => 'image',
                'original_name' => $file?->getClientOriginalName(),
                'alt_ckb' => $validated['alt_ckb'] ?? null,
                'alt_ar' => $validated['alt_ar'] ?? null,
                'alt_en' => $validated['alt_en'] ?? null,
                // Credit was accepted by the form and then dropped.
                'credit' => $validated['credit'] ?? null,
                'uploaded_by' => $request->user()?->id,
            ]);
        } catch (DuplicateMediaException) {
            // An ordinary outcome, told plainly. The redundant bytes are
            // already removed by the service.
            return back()->withErrors(['file' => __('media.errors.duplicate')]);
        }

        // Null means the bytes were compensated or recorded for the sweep;
        // there is nothing half-created to explain away.
        if ($media === null) {
            return back()->withErrors(['file' => __('projects.wizard.creation.error_media_upload')]);
        }

        // The upload audit is written INSIDE the service transaction, so a
        // committed upload can no longer be reported as a failure because a
        // separate audit call threw afterwards.

        return back()->with('success', __('app.states.saved'));
    }

    public function update(Request $request, Project $project, int $media): RedirectResponse
    {
        ProjectScope::authorise($request, $project);

        $validated = $request->validate([
            'alt_ckb' => ['nullable', 'string', 'max:191'],
            'alt_ar' => ['nullable', 'string', 'max:191'],
            'alt_en' => ['nullable', 'string', 'max:191'],
            'credit' => ['nullable', 'string', 'max:191'],
            'is_cover' => ['boolean'],
        ]);

        /*
         * ONE call, one transaction. Fields, cover state and audit either all
         * commit or none do — the previous order saved the text and only then
         * asked about the cover, so a refusal arrived after half the edit had
         * already landed.
         */
        $wantsCover = array_key_exists('is_cover', $validated)
            ? (bool) $validated['is_cover']
            : null;

        unset($validated['is_cover']);

        $result = $this->media->updateFields($project->id, (int) $media, $validated, $wantsCover);

        if (! $result['ok']) {
            return back()->withErrors(['is_cover' => $result['reason']]);
        }

        return back()->with('success', __('app.states.saved'));
    }

    public function destroy(Request $request, Project $project, int $media): RedirectResponse
    {
        ProjectScope::authorise($request, $project);

        /*
         * The row is the LAST REFERENCE to the file. Deleting it when the
         * bytes could not be removed leaves storage nothing can ever find
         * again — on a shared host that is unaccounted space growing with
         * every failed delete.
         *
         * So: remove the file first, and only drop the row once that is
         * confirmed. On failure the row survives, flagged, and the scheduled
         * sweep retries it.
         */

        // One writer. The controller no longer manages the invariant itself.
        if (! $this->media->delete($project->id, (int) $media)) {
            return back()->withErrors(['media' => __('projects.wizard.creation.error_media_cleanup')]);
        }

        return back();

    }

    /** @return list<array<string, mixed>> */
    private function collection(Project $project): array
    {
        return ProjectMedia::query()
            ->where('project_id', $project->id)
            // Rows awaiting cleanup are on their way out; showing them
            // offers an image that is about to stop existing.
            ->where('cleanup_pending', false)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (ProjectMedia $m): array => [
                'id' => $m->id,
                'url' => $m->url(),
                'original_name' => $m->original_name,
                'width' => $m->width,
                'height' => $m->height,
                'size_bytes' => $m->size_bytes,
                'alt_ckb' => $m->alt_ckb,
                'alt_ar' => $m->alt_ar,
                'alt_en' => $m->alt_en,
                'credit' => $m->credit,
                'is_cover' => $m->is_cover,
                // Surfaced because an image with no alt text is invisible to a
                // screen reader and to a search engine, and it is the single
                // most-skipped field in any media upload form.
                'missing_alt' => trim((string) $m->alt_ckb) === '',
            ])->all();
    }
}
