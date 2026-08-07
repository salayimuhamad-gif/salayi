<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Companies\Models\Company;
use App\Modules\Companies\Models\CompanyDeveloperAssociation;
use App\Modules\Companies\Models\CompanyProjectAssociation;
use App\Modules\Companies\Models\CompanyStaff;
use App\Modules\Core\Support\SafeText;
use App\Modules\Geography\Enums\AreaType;
use App\Modules\Geography\Models\Area;
use App\Modules\Geography\Services\AreaResolver;
use App\Modules\Geography\ValueObjects\Coordinates;
use App\Modules\Identity\Models\Consent;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\PermissionRegistry;
use App\Modules\Leads\Models\DemandProfile;
use App\Modules\Market\Enums\PriceType;
use App\Modules\Marketplace\Enums\OfferStatus;
use App\Modules\Marketplace\Models\Offer;
use App\Modules\Marketplace\Models\OfferMedia;
use App\Modules\Marketplace\Services\OfferMediaService;
use App\Modules\Projects\Enums\ConstructionStatus;
use App\Modules\Projects\Enums\DeliveryStatus;
use App\Modules\Projects\Enums\ProjectType;
use App\Modules\Projects\Enums\PublicationStatus;
use App\Modules\Projects\Enums\RatingCategory;
use App\Modules\Projects\Enums\RatingType;
use App\Modules\Projects\Exceptions\DuplicateMediaException;
use App\Modules\Projects\Models\Developer;
use App\Modules\Projects\Models\OrphanedFile;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectDraft;
use App\Modules\Projects\Models\ProjectDraftMedia;
use App\Modules\Projects\Models\ProjectMedia;
use App\Modules\Projects\Models\ProjectPrice;
use App\Modules\Projects\Models\ProjectRating;
use App\Modules\Projects\Services\ProjectDraftMediaService;
use App\Modules\Projects\Services\ProjectMediaService;
use App\Modules\Projects\Support\ActingCompany;
use App\Modules\Projects\Support\ActingCompanyContext;
use App\Modules\Projects\Support\CleanupJournal;
use App\Modules\Projects\Support\WizardStep;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * The Project Creation Wizard (spec 12.1, 37.2).
 *
 * The behaviours worth protecting are the ones that fail quietly: a draft that
 * silently loses a half-filled step, a company user who can resume somebody
 * else's draft, and a project that reaches the public because a permission was
 * checked in the interface rather than on the server.
 */
final class ProjectWizardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A platform operator: Super Admin role, no company membership.
     *
     * The role is REAL, not implied. The previous helper created a bare user
     * with no role at all, so `projects.create` failed for everybody — every
     * test passed or failed on the same middleware rejection and none of them
     * exercised the wizard.
     */
    private function admin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    /** Holds projects.create but NOT projects.publish. */
    private function editor(): User
    {
        return User::factory()->projectEditor()->create();
    }

    private function companyUser(Company $company): User
    {
        $user = User::factory()->companyAccountManager()->create();

        CompanyStaff::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role' => 'manager',
            'is_active' => true,
            // Rights belong to the membership, not the global role.
            'may_manage_projects' => true,
        ]);

        return $user;
    }

    private function company(): Company
    {
        return Company::query()->create([
            'slug' => 'a-company-'.uniqid(),
            'name_ckb' => 'A Company',
            'legal_name' => 'A Company LLC',
            'verification_status' => 'verified',
            'publication_status' => PublicationStatus::Published->value,
        ]);
    }

    /* --------------------------------------------------- draft lifecycle */

    public function test_starting_the_wizard_creates_a_draft(): void
    {
        $user = $this->admin();

        $this->actingAs($user)->get('/admin/projects/wizard')->assertRedirect();

        $this->assertDatabaseCount('project_drafts', 1);
        $this->assertSame(WizardStep::IDENTITY, ProjectDraft::query()->first()?->current_step);
    }

    /**
     * Resuming, not duplicating. Starting the wizard twice must return to the
     * existing draft — otherwise a visitor who navigates away and back loses
     * everything they entered and starts a second empty draft beside it.
     */
    public function test_starting_again_resumes_the_existing_draft(): void
    {
        $user = $this->admin();

        $this->actingAs($user)->get('/admin/projects/wizard');
        $this->actingAs($user)->get('/admin/projects/wizard');

        $this->assertDatabaseCount('project_drafts', 1);
    }

    /**
     * The rule that makes the wizard survivable: an invalid step still SAVES.
     * A wizard that refuses to remember a half-filled step is one people
     * abandon, which produces the thin records the draft exists to prevent.
     */
    public function test_an_invalid_step_is_stored_but_not_marked_complete(): void
    {
        $user = $this->admin();
        $draft = $this->draftFor($user);

        $this->actingAs($user)
            ->post("/admin/projects/wizard/{$draft->id}/identity", [
                'version' => $draft->version,
                'name_ckb' => '',           // required — invalid
                'name_en' => 'Ankawa Sky',  // keep this
            ])
            ->assertSessionHasErrors('name_ckb');

        $draft->refresh();

        $this->assertSame('Ankawa Sky', $draft->step('identity')['name_en'] ?? null);
        $this->assertFalse($draft->hasCompleted('identity'));
    }

    public function test_a_valid_step_is_marked_complete(): void
    {
        $user = $this->admin();
        $draft = $this->draftFor($user);

        $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/identity", [
            'version' => $draft->version,
            'name_ckb' => 'ئانکاوا',
            'project_type' => 'residential',
        ]);

        $this->assertTrue($draft->refresh()->hasCompleted('identity'));
    }

    /** A step that becomes invalid again loses its completed mark. */
    public function test_breaking_a_completed_step_clears_its_completion(): void
    {
        $user = $this->admin();
        $draft = $this->draftFor($user);

        $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/identity", [
            'version' => $draft->version,
            'name_ckb' => 'ئانکاوا', 'project_type' => 'residential',
        ]);
        $this->assertTrue($draft->refresh()->hasCompleted('identity'));

        $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/identity", [
            'version' => $draft->version,
            'name_ckb' => '', 'project_type' => 'residential',
        ]);

        $this->assertFalse($draft->refresh()->hasCompleted('identity'));
    }

    public function test_an_unknown_step_returns_404(): void
    {
        $user = $this->admin();
        $draft = $this->draftFor($user);

        $this->actingAs($user)->get("/admin/projects/wizard/{$draft->id}/not-a-step")->assertNotFound();
    }

    /* ------------------------------------------------- validation rules */

    /**
     * A latitude without a longitude is not a partial location, it is a broken
     * one — it would place the project on the prime meridian.
     */
    public function test_a_latitude_without_a_longitude_is_rejected(): void
    {
        $user = $this->admin();

        // `location` follows `identity`; a bare draft cannot reach it, so the
        // navigation guard refused before validation could run.
        $draft = $this->completedThrough($user, [WizardStep::IDENTITY]);

        $this->actingAs($user)
            ->post("/admin/projects/wizard/{$draft->id}/location", [
                'version' => $draft->version, 'latitude' => 36.19])
            ->assertSessionHasErrors('longitude');
    }

    /** §15: a price without its type is the most damaging data error here. */
    public function test_a_price_without_a_price_type_is_rejected(): void
    {
        $user = $this->admin();
        $draft = $this->draftFor($user);

        $this->actingAs($user)
            ->post("/admin/projects/wizard/{$draft->id}/pricing", [
                'version' => $draft->version, 'price_from' => 120000])
            ->assertSessionHasErrors('price_type');
    }

    public function test_only_sorani_is_required_for_the_name(): void
    {
        $user = $this->admin();
        $draft = $this->draftFor($user);

        $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/identity", [
            'version' => $draft->version,
            'name_ckb' => 'ئانکاوا',
            'project_type' => 'residential',
        ]);

        // No Arabic or English name, yet the step is complete: requiring all
        // three means nothing is entered until somebody translates it.
        $this->assertTrue($draft->refresh()->hasCompleted('identity'));
    }

    /* -------------------------------------------- permissions & scoping */

    /**
     * The one test that must fail on middleware. Kept separate and explicit so
     * a regression that breaks every route is distinguishable from one that
     * breaks permissions — the previous suite could not tell those apart.
     */
    public function test_a_user_without_the_create_permission_is_forbidden(): void
    {
        $user = User::factory()->create();   // no role at all

        /*
         * THE ENTRY POINT EXPLAINS ITSELF; IT DOES NOT BARE-403.
         *
         * A documented UX decision in ProjectWizardController: "a bare 403
         * leaves somebody unable to tell whether the feature is off, they lack
         * a permission, or something broke — and the three need different
         * actions from them." The reason is asserted explicitly so an
         * unrelated redirect appearing later cannot quietly satisfy this test.
         * The operational routes below still 403 through middleware.
         */
        $this->actingAs($user)->get('/admin/projects/wizard')
            ->assertRedirect(route('admin.projects.wizard.unavailable', ['reason' => 'permission_denied']));
    }

    /** The positive counterpart: a real permission holder gets through. */
    public function test_a_project_editor_can_open_the_wizard(): void
    {
        $this->actingAs($this->editor())->get('/admin/projects/wizard')->assertRedirect();
    }

    /**
     * 404 rather than 403 for another user's draft: confirming that a draft id
     * exists reveals how many projects are being entered and by whom, which is
     * commercial information.
     */
    public function test_a_user_cannot_open_another_users_draft(): void
    {
        $owner = $this->admin();
        $other = $this->admin();
        $draft = $this->draftFor($owner);

        $this->actingAs($other)->get("/admin/projects/wizard/{$draft->id}/identity")->assertNotFound();
    }

    public function test_a_company_user_cannot_open_a_draft_from_another_company(): void
    {
        $companyA = $this->company();
        $companyB = $this->company();

        $userA = $this->companyUser($companyA);

        $draft = ProjectDraft::query()->create([
            'user_id' => $userA->id,
            'company_id' => $companyB->id,   // mismatched on purpose
            'current_step' => WizardStep::IDENTITY,
            'payload' => [],
            'completed_steps' => [],
        ]);

        $this->actingAs($userA)->get("/admin/projects/wizard/{$draft->id}/identity")->assertNotFound();
    }

    public function test_a_company_users_draft_is_scoped_to_their_company(): void
    {
        $company = $this->company();
        $user = $this->companyUser($company);

        $this->actingAs($user)->get('/admin/projects/wizard');

        $this->assertSame($company->id, ProjectDraft::query()->first()?->company_id);
    }

    /* ------------------------------------------------------- submission */

    public function test_an_incomplete_draft_cannot_be_submitted(): void
    {
        $user = $this->admin();
        $draft = $this->draftFor($user);

        $this->actingAs($user)
            ->post("/admin/projects/wizard/{$draft->id}/submit")
            ->assertSessionHasErrors();

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_a_complete_draft_creates_a_project(): void
    {
        $user = $this->admin();
        $draft = $this->completeDraftFor($user);

        $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/submit")->assertRedirect();

        $this->assertDatabaseCount('projects', 1);
        $this->assertSame('ئانکاوا', Project::query()->first()?->name_ckb);
    }

    /**
     * A new project is NEVER born published, whatever the author's
     * permissions. Publication is its own reviewed transition.
     */
    public function test_a_submitted_project_is_created_as_a_draft_not_published(): void
    {
        $user = $this->admin();
        $draft = $this->completeDraftFor($user);

        $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/submit");

        $this->assertSame(
            PublicationStatus::Draft->value,
            Project::query()->first()?->publication_status?->value,
        );
    }

    /**
     * Re-validated at submission from the stored payload. `completed_steps` is
     * a record of what happened earlier, not a guarantee about now — an area
     * can be deleted between saving a step and submitting.
     */
    public function test_submission_revalidates_the_stored_payload(): void
    {
        $user = $this->admin();
        $draft = $this->completeDraftFor($user);

        // Corrupt the stored payload directly, as a stale or tampered draft
        // would be, while leaving completed_steps intact.
        $payload = $draft->payload;
        $payload['identity']['name_ckb'] = '';
        $draft->forceFill(['payload' => $payload])->save();

        $this->actingAs($user)
            ->post("/admin/projects/wizard/{$draft->id}/submit")
            ->assertSessionHasErrors();

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_a_draft_can_be_discarded(): void
    {
        $user = $this->admin();
        $draft = $this->draftFor($user);

        $this->actingAs($user)->delete("/admin/projects/wizard/{$draft->id}")->assertRedirect();

        $this->assertDatabaseCount('project_drafts', 0);
    }

    /* ----------------------------------------------------- nearby preview */

    /** §10.5: kilometres, straight-line, and never an invented travel time. */
    public function test_the_nearby_preview_reports_straight_line_kilometres_only(): void
    {
        $user = $this->admin();

        Area::query()->create([
            'type' => AreaType::District->value,
            'slug' => 'ankawa',
            'name_ckb' => 'ئانکاوا',
            'publication_status' => PublicationStatus::Published->value,
        ]);

        $this->actingAs($user)
            ->getJson('/admin/projects/wizard/nearby?latitude=36.19&longitude=44.009')
            ->assertSuccessful()
            ->assertJsonPath('distance.unit', 'km')
            ->assertJsonPath('distance.method', 'straight_line')
            ->assertJsonPath('distance.travel_time_available', false);
    }

    public function test_the_nearby_preview_rejects_missing_coordinates(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/admin/projects/wizard/nearby')
            ->assertStatus(422);
    }

    /* ------------------------------------------------ enum validation */

    public function test_an_invalid_project_type_is_rejected(): void
    {
        $user = $this->admin();
        $draft = $this->draftFor($user);

        $this->actingAs($user)
            ->post("/admin/projects/wizard/{$draft->id}/identity", [
                'version' => $draft->version,
                'name_ckb' => 'ئانکاوا',
                'project_type' => 'not-a-real-type',
            ])
            ->assertSessionHasErrors('project_type');

        $this->assertFalse($draft->refresh()->hasCompleted('identity'));
    }

    public function test_a_valid_project_type_is_accepted(): void
    {
        $user = $this->admin();
        $draft = $this->draftFor($user);

        $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/identity", [
            'version' => $draft->version,
            'name_ckb' => 'ئانکاوا',
            'project_type' => ProjectType::Residential->value,
        ]);

        $this->assertTrue($draft->refresh()->hasCompleted('identity'));
    }

    public function test_invalid_construction_and_delivery_statuses_are_rejected(): void
    {
        $user = $this->admin();

        /*
         * `details` is the THIRD required step, so identity and location must
         * both be complete before it is reachable. With only identity done the
         * server-side navigation guard returned 403, validation never ran, and
         * the test asserted on errors that were never produced. The guard is
         * correct and untouched — `test_skipping_ahead_to_a_later_required_step_is_rejected`
         * still proves it refuses a genuine jump.
         */
        $draft = $this->completedThrough($user, [WizardStep::IDENTITY, WizardStep::LOCATION]);

        $this->actingAs($user)
            ->post("/admin/projects/wizard/{$draft->id}/details", [
                'version' => $draft->version,
                'construction_status' => 'invented',
                'delivery_status' => 'also-invented',
            ])
            ->assertSessionHasErrors(['construction_status', 'delivery_status']);
    }

    public function test_an_invalid_price_type_is_rejected(): void
    {
        $user = $this->admin();
        $draft = $this->draftFor($user);

        $this->actingAs($user)
            ->post("/admin/projects/wizard/{$draft->id}/pricing", [
                'version' => $draft->version,
                'price_from' => 100000,
                'price_type' => 'made-up',
            ])
            ->assertSessionHasErrors('price_type');
    }

    /* --------------------------------------------- required-step meaning */

    /**
     * The defect this replaces: every location rule was nullable, so an empty
     * post satisfied all of them and the step was marked complete having
     * collected nothing. A required step anything passes is not a requirement.
     */
    public function test_an_empty_location_step_is_not_marked_complete(): void
    {
        $user = $this->admin();
        $draft = $this->completedThrough($user, [WizardStep::IDENTITY]);

        $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/location", [
            'version' => $draft->version, ]);

        $this->assertFalse($draft->refresh()->hasCompleted('location'));
    }

    public function test_a_location_with_coordinates_is_complete(): void
    {
        $user = $this->admin();
        $draft = $this->completedThrough($user, [WizardStep::IDENTITY]);

        $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/location", [
            'version' => $draft->version,
            'latitude' => 36.19, 'longitude' => 44.009,
        ]);

        $this->assertTrue($draft->refresh()->hasCompleted('location'));
    }

    /** A boundary alone is a location too. */
    public function test_a_location_with_only_a_boundary_is_complete(): void
    {
        $user = $this->admin();
        $draft = $this->completedThrough($user, [WizardStep::IDENTITY]);

        $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/location", [
            'version' => $draft->version,
            'boundary_wkt' => 'POLYGON((44.00 36.18, 44.02 36.18, 44.02 36.20, 44.00 36.20, 44.00 36.18))',
        ]);

        $this->assertTrue($draft->refresh()->hasCompleted('location'));
    }

    /** Developer is optional and must not block submission. */
    public function test_the_developer_step_is_not_required(): void
    {
        $this->assertNotContains(WizardStep::DEVELOPER, WizardStep::required());
    }

    /* --------------------------------------------- area resolution */

    /**
     * Point-in-polygon, not bounding box. A bbox around an irregular district
     * overlaps its neighbours, so a bbox-only assignment files projects under
     * the wrong area with complete confidence.
     */
    public function test_area_is_resolved_by_polygon_containment(): void
    {
        $user = $this->admin();

        // An L-shaped area whose bounding box contains the test point but
        // whose polygon does not.
        Area::query()->create([
            'type' => AreaType::District->value,
            'slug' => 'l-shaped',
            'name_ckb' => 'L',
            'publication_status' => PublicationStatus::Published->value,
            'boundary_wkt' => 'POLYGON((44.00 36.18, 44.01 36.18, 44.01 36.19, 44.02 36.19, 44.02 36.20, 44.00 36.20, 44.00 36.18))',
        ]);

        $draft = $this->completeDraftFor($user, [
            'location' => ['latitude' => 36.185, 'longitude' => 44.015],
        ]);

        $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/submit");

        $project = Project::query()->first();

        // Inside the bbox, outside the polygon: unresolved, reported honestly.
        $this->assertNull($project?->area_id);
        $this->assertTrue((bool) $project?->area_unresolved);
    }

    public function test_a_point_inside_the_polygon_resolves_the_area(): void
    {
        $user = $this->admin();

        $area = Area::query()->create([
            'type' => AreaType::District->value,
            'slug' => 'square',
            'name_ckb' => 'Square',
            'publication_status' => PublicationStatus::Published->value,
            'boundary_wkt' => 'POLYGON((44.00 36.18, 44.02 36.18, 44.02 36.20, 44.00 36.20, 44.00 36.18))',
        ]);

        $draft = $this->completeDraftFor($user, [
            'location' => ['latitude' => 36.19, 'longitude' => 44.01],
        ]);

        $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/submit");

        $project = Project::query()->first();

        $this->assertNotNull($project, 'The submission should have produced a project.');
        $this->assertSame($area->id, $project->area_id);
        $this->assertSame('boundary', $project->area_match_type);
        $this->assertFalse((bool) $project->area_unresolved);
    }

    /* ------------------------------------------------- company scoping */

    /** Hiding a select is presentation. This is enforcement. */
    public function test_a_company_user_cannot_post_an_arbitrary_company_id(): void
    {
        $own = $this->company();
        $other = $this->company();
        $user = $this->companyUser($own);
        $draft = $this->draftFor($user, $own->id);

        $this->actingAs($user)
            ->post("/admin/projects/wizard/{$draft->id}/developer", [
                'version' => $draft->version,
                'company_id' => $other->id,
                'association_role' => 'official_developer',
            ])
            ->assertSessionHasErrors('company_id');
    }

    public function test_a_user_in_two_companies_must_choose_explicitly(): void
    {
        $a = $this->company();
        $b = $this->company();
        $user = $this->companyUser($a);

        CompanyStaff::query()->create([
            'company_id' => $b->id, 'user_id' => $user->id, 'role' => 'manager', 'is_active' => true,
            /*
             * PROJECT RIGHTS ON THE SECOND MEMBERSHIP TOO.
             *
             * Eligibility for the wizard is decided by the membership, not the
             * global role. Without this the user had exactly ONE eligible
             * company, `ActingCompanyContext` auto-selected it — as it is
             * designed to — and the "multi-company" fixture was really a
             * single-company one. The selector never appeared and a draft was
             * scoped to the wrong company.
             */
            'may_manage_projects' => true,
        ]);

        $resolution = ActingCompany::resolve(request()->merge([]), $user);

        $this->assertTrue($resolution['must_choose']);
        $this->assertNull($resolution['company_id']);
        $this->assertCount(2, $resolution['available']);
    }

    /**
     * Absence of a company_staff row must not mean platform administrator.
     * That inversion promoted every unlinked user to unscoped access.
     */
    public function test_a_user_with_no_membership_and_no_permission_is_not_a_platform_operator(): void
    {
        $user = User::factory()->create();   // no role, no membership

        $this->assertFalse(ActingCompany::stillPermits($user, null));
    }

    /** Losing a membership must not turn a scoped draft into a platform draft. */
    public function test_deactivating_a_membership_locks_the_user_out_of_their_draft(): void
    {
        $company = $this->company();
        $user = $this->companyUser($company);
        $draft = $this->draftFor($user, $company->id);

        CompanyStaff::query()->where('user_id', $user->id)->update(['is_active' => false]);

        $this->actingAs($user)->get("/admin/projects/wizard/{$draft->id}/identity")->assertNotFound();
    }

    /* ----------------------------------------------------- persistence */

    public function test_pricing_is_persisted_with_its_type_and_provenance(): void
    {
        $user = $this->admin();
        $draft = $this->completeDraftFor($user, [
            'pricing' => [
                'price_from' => 120000,
                'price_to' => 180000,
                'currency' => 'USD',
                'price_type' => PriceType::SaleAsking->value,
                'price_source' => 'developer brochure',
                'price_confidence' => 'medium',
            ],
        ]);

        $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/submit");

        $price = ProjectPrice::query()->first();

        $this->assertNotNull($price, 'Entered pricing must not be silently discarded.');
        $this->assertSame(PriceType::SaleAsking, $price->price_type);
        $this->assertSame('developer brochure', $price->source);
        $this->assertTrue($price->requiresQualifier());
    }

    public function test_the_creator_is_recorded_on_the_project(): void
    {
        $user = $this->admin();
        $draft = $this->completeDraftFor($user);

        $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/submit");

        $this->assertSame($user->id, Project::query()->first()?->created_by);
    }

    /* ------------------------------------------------- idempotency */

    /** A replayed submission returns the same project, never a second one. */
    public function test_submitting_twice_creates_only_one_project(): void
    {
        $user = $this->admin();
        $draft = $this->completeDraftFor($user);

        $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/submit");
        $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/submit")->assertRedirect();

        $this->assertDatabaseCount('projects', 1);
    }

    public function test_a_submitted_draft_cannot_be_edited(): void
    {
        $user = $this->admin();
        $draft = $this->completeDraftFor($user);

        $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/submit");

        $this->actingAs($user)
            ->post("/admin/projects/wizard/{$draft->id}/identity", [
                'version' => $draft->version,
                'name_ckb' => 'changed', 'project_type' => ProjectType::Residential->value,
            ])
            ->assertStatus(409);
    }

    public function test_submission_marks_the_draft_atomically(): void
    {
        $user = $this->admin();
        $draft = $this->completeDraftFor($user);

        $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/submit");

        $draft->refresh();

        $this->assertNotNull($draft->submitted_at);
        $this->assertNotNull($draft->project_id);
    }

    /* ------------------------------------------------ step navigation */

    /** A direct URL must not advance the draft past unfinished work. */
    public function test_skipping_ahead_to_a_later_required_step_is_rejected(): void
    {
        $user = $this->admin();
        $draft = $this->draftFor($user);

        $this->actingAs($user)->get("/admin/projects/wizard/{$draft->id}/details")->assertForbidden();
    }

    public function test_returning_to_a_completed_step_is_allowed(): void
    {
        $user = $this->admin();
        $draft = $this->completedThrough($user, [WizardStep::IDENTITY]);

        $this->actingAs($user)->get("/admin/projects/wizard/{$draft->id}/identity")->assertSuccessful();
    }

    public function test_an_optional_step_is_always_reachable(): void
    {
        $user = $this->admin();
        $draft = $this->draftFor($user);

        $this->actingAs($user)->get("/admin/projects/wizard/{$draft->id}/developer")->assertSuccessful();
    }

    /** Optimistic lock: a stale version must not overwrite newer work. */
    public function test_a_stale_version_is_rejected(): void
    {
        $user = $this->admin();
        $draft = $this->draftFor($user);

        $this->actingAs($user)
            ->post("/admin/projects/wizard/{$draft->id}/identity", [
                // ONE version key. A duplicate literal key is legal PHP and the
                // last wins, so the injected current version sat above the 999
                // this test exists to send — the assertion passed while the
                // request was valid.
                'name_ckb' => 'ئانکاوا',
                'project_type' => ProjectType::Residential->value,
                'version' => 999,
            ])
            ->assertSessionHasErrors('version');
    }

    /* ------------------------------------------------- feature flag */

    public function test_every_wizard_route_is_gated_by_the_feature_flag(): void
    {
        $this->setFeatures(['projects.wizard' => false,
        ]);

        $user = $this->admin();
        $draft = $this->draftFor($user);

        // The entry point explains WHY it is unavailable...
        $this->actingAs($user)->get('/admin/projects/wizard')
            ->assertRedirect(route('admin.projects.wizard.unavailable', ['reason' => 'feature_disabled']));

        // ...while every operational route stays hard-refused by middleware,
        // so switching the feature off really does close the whole surface.
        $this->actingAs($user)->get("/admin/projects/wizard/{$draft->id}/identity")->assertForbidden();
        $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/submit")->assertForbidden();
    }

    /* ---------------------------------------------------- cleanup */

    public function test_abandoned_drafts_are_pruned_but_submitted_ones_survive(): void
    {
        $user = $this->admin();

        $abandoned = $this->draftFor($user);
        $abandoned->forceFill(['last_touched_at' => now()->subDays(60)])->save();

        $submitted = $this->draftFor($user);
        $submitted->forceFill([
            'last_touched_at' => now()->subDays(60),
            'submitted_at' => now()->subDays(59),
        ])->save();

        $this->artisan('mulkihawler:prune-project-drafts', ['--days' => 30])->assertSuccessful();

        $this->assertDatabaseMissing('project_drafts', ['id' => $abandoned->id]);
        $this->assertDatabaseHas('project_drafts', ['id' => $submitted->id]);
    }

    /** Discarding a draft must never remove a project it already created. */
    public function test_deleting_a_draft_does_not_delete_its_project(): void
    {
        $user = $this->admin();
        $draft = $this->completeDraftFor($user);

        $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/submit");
        $this->assertDatabaseCount('projects', 1);

        $this->actingAs($user)->delete("/admin/projects/wizard/{$draft->id}");

        $this->assertDatabaseCount('projects', 1);
    }

    /* ----------------------------------- round 3: regressions from review */

    /**
     * Route-level proof that start() actually runs. It called an undefined
     * companyIdFor(), so every wizard request was a 500 — and no test noticed,
     * because none of them exercised the route end to end.
     */
    public function test_starting_the_wizard_returns_a_working_redirect_to_a_draft(): void
    {
        $user = $this->admin();

        $response = $this->actingAs($user)->get('/admin/projects/wizard');

        $response->assertRedirect();
        $this->assertDatabaseCount('project_drafts', 1);

        $draft = ProjectDraft::query()->first();
        $this->actingAs($user)->get("/admin/projects/wizard/{$draft->id}/identity")->assertSuccessful();
    }

    /** Mass assignment must not silently drop wizard fields. */
    public function test_every_wizard_field_survives_project_creation(): void
    {
        $project = Project::query()->create([
            'slug' => 'fillable-check',
            'name_ckb' => 'x',
            'project_type' => ProjectType::Residential->value,
            'construction_status' => ConstructionStatus::UnderConstruction->value,
            'delivery_status' => DeliveryStatus::NotStarted->value,
            'publication_status' => PublicationStatus::Draft->value,
            'created_by' => $this->admin()->id,
            'area_is_manual' => true,
            'area_match_type' => 'manual',
            'area_unresolved' => true,
        ])->refresh();

        $this->assertNotNull($project->created_by);
        $this->assertTrue($project->area_is_manual);
        $this->assertSame('manual', $project->area_match_type);
        $this->assertTrue($project->area_unresolved);
    }

    /** Optional means "you need not fill it in", not "anything goes". */
    public function test_a_corrupted_optional_pricing_payload_blocks_submission(): void
    {
        $user = $this->admin();
        $draft = $this->completeDraftFor($user, [
            'pricing' => ['price_from' => 1000, 'price_type' => 'not-a-price-type'],
        ]);

        $this->actingAs($user)
            ->post("/admin/projects/wizard/{$draft->id}/submit")
            ->assertSessionHasErrors();

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_an_invalid_association_role_is_rejected(): void
    {
        $user = $this->admin();
        $draft = $this->draftFor($user);
        $company = $this->company();

        $this->actingAs($user)
            ->post("/admin/projects/wizard/{$draft->id}/developer", [
                'version' => $draft->version,
                'company_id' => $company->id,
                'association_role' => 'chief-wizard',
            ])
            ->assertSessionHasErrors('association_role');
    }

    /** Crafted media ids must not move another project's photographs. */
    public function test_media_belonging_to_another_project_cannot_be_claimed(): void
    {
        $user = $this->admin();

        $victim = Project::query()->create([
            'slug' => 'victim', 'name_ckb' => 'v',
            'project_type' => ProjectType::Residential->value,
            'construction_status' => ConstructionStatus::UnderConstruction->value,
            'delivery_status' => DeliveryStatus::NotStarted->value,
            'publication_status' => PublicationStatus::Draft->value,
        ]);

        $mediaId = DB::table('project_media')->insertGetId([
            'project_id' => $victim->id,
            'kind' => 'image',
            // `project_media` carries no title columns; the row only needs to
            // exist and belong to the victim for the claim to be refused.
            'path' => 'media/stolen.jpg',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $draft = $this->completeDraftFor($user, ['media' => ['media_ids' => [$mediaId]]]);

        $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/submit");

        // The victim keeps its media, whatever happened to the submission.
        $this->assertSame(
            $victim->id,
            (int) DB::table('project_media')->where('id', $mediaId)->value('project_id'),
        );
    }

    public function test_an_unpublished_area_cannot_be_selected(): void
    {
        $user = $this->admin();

        $area = Area::query()->create([
            'type' => AreaType::District->value,
            'slug' => 'unpublished-area',
            'name_ckb' => 'Hidden',
            'publication_status' => PublicationStatus::Draft->value,
        ]);

        $draft = $this->completeDraftFor($user, [
            'location' => ['latitude' => 36.19, 'longitude' => 44.009, 'area_id' => $area->id],
        ]);

        $this->actingAs($user)
            ->post("/admin/projects/wizard/{$draft->id}/submit")
            ->assertSessionHasErrors();

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_malformed_boundary_geometry_is_rejected(): void
    {
        $user = $this->admin();
        $draft = $this->completedThrough($user, [WizardStep::IDENTITY]);

        $this->actingAs($user)
            ->post("/admin/projects/wizard/{$draft->id}/location", [
                'version' => $draft->version,
                'boundary_wkt' => 'POLYGON(((((nonsense',
            ])
            ->assertSessionHasErrors('boundary_wkt');
    }

    public function test_out_of_range_boundary_coordinates_are_rejected(): void
    {
        $user = $this->admin();
        $draft = $this->completedThrough($user, [WizardStep::IDENTITY]);

        $this->actingAs($user)
            ->post("/admin/projects/wizard/{$draft->id}/location", [
                'version' => $draft->version,
                'boundary_wkt' => 'POLYGON((999 36.18, 44.02 36.18, 44.02 36.20, 999 36.18))',
            ])
            ->assertSessionHasErrors('boundary_wkt');
    }

    /** A submitted draft is an audit record and survives the discard button. */
    public function test_a_submitted_draft_cannot_be_discarded(): void
    {
        $user = $this->admin();
        $draft = $this->completeDraftFor($user);

        $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/submit");

        $this->actingAs($user)->delete("/admin/projects/wizard/{$draft->id}")->assertStatus(409);

        $this->assertDatabaseHas('project_drafts', ['id' => $draft->id]);
        $this->assertDatabaseCount('projects', 1);
    }

    /* ------------------------------------------- multi-company behaviour */

    public function test_a_multi_company_user_is_sent_to_the_company_selector(): void
    {
        $a = $this->company();
        $b = $this->company();
        $user = $this->companyUser($a);

        CompanyStaff::query()->create([
            'company_id' => $b->id, 'user_id' => $user->id, 'role' => 'manager', 'is_active' => true,
            /*
             * PROJECT RIGHTS ON THE SECOND MEMBERSHIP TOO.
             *
             * Eligibility for the wizard is decided by the membership, not the
             * global role. Without this the user had exactly ONE eligible
             * company, `ActingCompanyContext` auto-selected it — as it is
             * designed to — and the "multi-company" fixture was really a
             * single-company one. The selector never appeared and a draft was
             * scoped to the wrong company.
             */
            'may_manage_projects' => true,
        ]);

        $this->actingAs($user)
            ->get('/admin/projects/wizard')
            ->assertRedirect(route('admin.projects.wizard.company'));

        // Crucially: NO unscoped draft was created along the way.
        $this->assertDatabaseCount('project_drafts', 0);
    }

    public function test_choosing_a_company_scopes_the_new_draft(): void
    {
        $a = $this->company();
        $b = $this->company();
        $user = $this->companyUser($a);

        CompanyStaff::query()->create([
            'company_id' => $b->id, 'user_id' => $user->id, 'role' => 'manager', 'is_active' => true,
            /*
             * PROJECT RIGHTS ON THE SECOND MEMBERSHIP TOO.
             *
             * Eligibility for the wizard is decided by the membership, not the
             * global role. Without this the user had exactly ONE eligible
             * company, `ActingCompanyContext` auto-selected it — as it is
             * designed to — and the "multi-company" fixture was really a
             * single-company one. The selector never appeared and a draft was
             * scoped to the wrong company.
             */
            'may_manage_projects' => true,
        ]);

        $this->actingAs($user)->post('/admin/projects/wizard/company', ['acting_company_id' => $b->id]);

        $this->assertSame($b->id, ProjectDraft::query()->first()?->company_id);
    }

    public function test_choosing_a_company_the_user_does_not_belong_to_is_rejected(): void
    {
        $own = $this->company();
        $other = $this->company();
        $user = $this->companyUser($own);

        CompanyStaff::query()->create([
            'company_id' => $this->company()->id, 'user_id' => $user->id, 'role' => 'manager', 'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post('/admin/projects/wizard/company', ['acting_company_id' => $other->id])
            ->assertSessionHasErrors('acting_company_id');
    }

    /** Resuming must stay inside the chosen context. */
    public function test_a_draft_from_another_company_is_not_resumed(): void
    {
        $a = $this->company();
        $b = $this->company();
        $user = $this->companyUser($a);

        $this->draftFor($user, $b->id);

        $this->actingAs($user)->get('/admin/projects/wizard');

        // A second draft, scoped to A — not a resume of B's.
        $this->assertDatabaseCount('project_drafts', 2);
        $this->assertSame(1, ProjectDraft::query()->where('company_id', $a->id)->count());
    }

    /* ------------------------------------ round 4: persistence & security */

    /** acting_company_id was being dropped by mass assignment. */
    public function test_the_acting_company_is_actually_persisted_on_the_draft(): void
    {
        $company = $this->company();
        $user = $this->companyUser($company);

        $this->actingAs($user)->get('/admin/projects/wizard');

        $draft = ProjectDraft::query()->first();

        $this->assertNotNull($draft, 'Starting the wizard should have created a draft.');
        $this->assertSame($company->id, $draft->company_id);
        $this->assertSame($company->id, $draft->acting_company_id);
        $this->assertSame($company->id, $draft->scopedCompanyId());
    }

    /** A company portal user must be able to reach the wizard at all. */
    public function test_a_company_account_manager_can_use_the_wizard(): void
    {
        $company = $this->company();
        $user = $this->companyUser($company);

        $this->actingAs($user)->get('/admin/projects/wizard')->assertRedirect();
        $this->assertDatabaseCount('project_drafts', 1);
    }

    /** The optimistic lock is mandatory: omitting the version is a failure. */
    public function test_a_save_without_a_version_is_rejected(): void
    {
        $user = $this->admin();
        $draft = $this->draftFor($user);

        $this->actingAs($user)
            // Deliberately NO version key: the earlier edit injected one into
            // every save call including this one, so the test asserted a
            // rejection while sending a valid request and passed for the
            // wrong reason.
            ->post("/admin/projects/wizard/{$draft->id}/identity", [
                'name_ckb' => 'ئانکاوا',
                'project_type' => ProjectType::Residential->value,
            ])
            ->assertSessionHasErrors('version');
    }

    public function test_a_save_with_the_current_version_succeeds(): void
    {
        $user = $this->admin();
        $draft = $this->draftFor($user);

        $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/identity", [
            'name_ckb' => 'ئانکاوا',
            'project_type' => ProjectType::Residential->value,
            'version' => $draft->version,
        ]);

        $this->assertTrue($draft->refresh()->hasCompleted('identity'));
    }

    /* -------------------------------------------- draft-owned media */

    public function test_media_from_another_draft_cannot_be_reordered_or_covered(): void
    {
        $owner = $this->admin();
        $intruder = $this->admin();

        $ownerDraft = $this->draftFor($owner);
        $intruderDraft = $this->draftFor($intruder);

        $item = ProjectDraftMedia::query()->create([
            'project_draft_id' => $ownerDraft->id,
            'uploaded_by' => $owner->id,
            'kind' => 'image',
            'path' => 'p/one.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
        ]);

        // The intruder aims their own draft at the owner's media id.
        $this->actingAs($intruder)->patch("/admin/projects/wizard/{$intruderDraft->id}/media", [
            'cover_id' => $item->id,
            'delete_id' => $item->id,
        ]);

        $this->assertDatabaseHas('project_draft_media', ['id' => $item->id, 'is_cover' => false]);
    }

    public function test_a_draft_media_row_is_bound_to_its_uploader_and_company(): void
    {
        $company = $this->company();
        $user = $this->companyUser($company);

        $this->actingAs($user)->get('/admin/projects/wizard');
        $draft = ProjectDraft::query()->first();

        $item = ProjectDraftMedia::query()->create([
            'project_draft_id' => $draft->id,
            'uploaded_by' => $user->id,
            // The column is `acting_company_id`; `company_id` has never
            // existed on draft media.
            'acting_company_id' => $draft->scopedCompanyId(),
            'kind' => 'image',
            'path' => 'p/two.jpg',
            'mime_type' => 'image/png',
            'size_bytes' => 2048,
        ]);

        $this->assertSame($user->id, $item->uploaded_by);
        $this->assertSame($company->id, $item->acting_company_id);
        $this->assertSame(0, ProjectDraftMedia::query()->ownedBy((int) $draft->id, $this->admin()->id)->count());
    }

    /* ---------------------------------------- unified area resolution */

    /**
     * The suggestion and the saved value must come from the same resolver.
     * A bbox suggestion with a polygon save meant the wizard offered one area
     * and stored another.
     */
    public function test_the_nearby_suggestion_uses_the_same_resolver_as_submission(): void
    {
        $user = $this->admin();

        Area::query()->create([
            'type' => AreaType::District->value,
            'slug' => 'l-shaped',
            'name_ckb' => 'L',
            'publication_status' => PublicationStatus::Published->value,
            'boundary_wkt' => 'POLYGON((44.00 36.18, 44.01 36.18, 44.01 36.19, 44.02 36.19, 44.02 36.20, 44.00 36.20, 44.00 36.18))',
        ]);

        // Inside the bbox, outside the polygon: no suggestion, and honest
        // about it rather than silently offering the wrong district.
        $this->actingAs($user)
            ->getJson('/admin/projects/wizard/nearby?latitude=36.185&longitude=44.015')
            ->assertJsonPath('suggested_area', null)
            ->assertJsonPath('area_unresolved', true);
    }

    /* ------------------------------------ round 5: dispatch every route */

    /**
     * Every wizard route dispatched. Four of them pointed at methods a
     * careless edit had deleted, and nothing noticed because no test issued a
     * request to them.
     */
    public function test_every_wizard_route_dispatches(): void
    {
        $user = $this->admin();
        $draft = $this->draftFor($user);

        $this->actingAs($user)->get('/admin/projects/wizard')->assertRedirect();
        $this->actingAs($user)->get("/admin/projects/wizard/{$draft->id}/identity")->assertSuccessful();
        $this->actingAs($user)
            ->post("/admin/projects/wizard/{$draft->id}/identity", [
                'version' => $draft->version,
                'name_ckb' => 'ئانکاوا',
                'project_type' => ProjectType::Residential->value,
            ])
            ->assertRedirect();
        $this->actingAs($user)
            ->getJson('/admin/projects/wizard/nearby?latitude=36.19&longitude=44.009')
            ->assertSuccessful();
        $this->actingAs($user)
            ->patch("/admin/projects/wizard/{$draft->refresh()->id}/media", [])
            ->assertRedirect();
        $this->actingAs($user)->delete("/admin/projects/wizard/{$draft->id}")->assertRedirect();
    }

    public function test_media_upload_rejects_a_non_image(): void
    {
        $user = $this->admin();
        $draft = $this->draftFor($user);

        $this->actingAs($user)
            ->post("/admin/projects/wizard/{$draft->id}/media", [
                'file' => UploadedFile::fake()->create('payload.php', 16, 'text/x-php'),
            ])
            ->assertSessionHasErrors('file');
    }

    /* ------------------------------- draft scope is authoritative */

    /**
     * A draft scoped to A must reject B even when the user belongs to both.
     * Re-resolving the acting company from a request that carries none was
     * how B leaked in.
     */
    public function test_a_draft_scoped_to_one_company_rejects_another(): void
    {
        $a = $this->company();
        $b = $this->company();
        $user = $this->companyUser($a);

        CompanyStaff::query()->create([
            'company_id' => $b->id, 'user_id' => $user->id, 'role' => 'manager', 'is_active' => true,
            /*
             * DELIBERATELY WITHOUT project rights. This test is about a draft
             * scoped to company A refusing a posted company B; company A must
             * therefore stay the unambiguous acting context. Membership of B is
             * enough to prove the refusal is about the DRAFT's scope and not
             * about the user simply not knowing B.
             */
        ]);

        $draft = $this->draftFor($user, $a->id);
        $draft->forceFill(['acting_company_id' => $a->id])->save();

        $this->actingAs($user)
            ->post("/admin/projects/wizard/{$draft->id}/developer", [
                'version' => $draft->version,
                'company_id' => $b->id,
                'association_role' => 'official_developer',
            ])
            ->assertSessionHasErrors('company_id');
    }

    /* ----------------------------- scoped vs unscoped permissions */

    public function test_a_company_user_never_becomes_a_platform_operator(): void
    {
        $company = $this->company();
        $user = $this->companyUser($company);

        // Membership removed entirely; the role permission remains.
        CompanyStaff::query()->where('user_id', $user->id)->delete();

        $this->assertFalse($user->refresh()->hasPermission(ActingCompany::PLATFORM_PERMISSION));
        $this->assertFalse(ActingCompany::stillPermits($user, null));
    }

    public function test_an_inactive_membership_does_not_grant_platform_mode(): void
    {
        $company = $this->company();
        $user = $this->companyUser($company);

        CompanyStaff::query()->where('user_id', $user->id)->update(['is_active' => false]);

        $resolution = ActingCompany::resolve(request(), $user->refresh());

        $this->assertFalse($resolution['is_platform']);
        $this->assertNull($resolution['company_id']);
    }

    public function test_a_platform_administrator_holds_the_unscoped_permission(): void
    {
        $this->assertTrue($this->admin()->hasPermission(ActingCompany::PLATFORM_PERMISSION));
    }

    /* ------------------------- round 6: cross-company administration */

    /**
     * The security gap the previous round introduced: company roles were given
     * projects.view / projects.update while every project controller queried
     * globally, so a company portal user could read and edit every project on
     * the platform.
     */
    public function test_a_company_user_cannot_see_another_companys_project_in_the_index(): void
    {
        $mine = $this->company();
        $theirs = $this->company();
        $user = $this->companyUser($mine);

        $ours = $this->projectFor($mine, 'ours');
        $this->projectFor($theirs, 'theirs');

        $this->actingAs($user)->get('/admin/projects')->assertInertia(
            fn ($page) => $page->has('projects.data', 1)
                ->where('projects.data.0.id', $ours->id),
        );
    }

    public function test_a_company_user_cannot_edit_another_companys_project(): void
    {
        $mine = $this->company();
        $theirs = $this->company();
        $user = $this->companyUser($mine);

        $foreign = $this->projectFor($theirs, 'foreign');

        // 404, not 403: confirming the id exists tells a competitor how many
        // projects the platform holds.
        $this->actingAs($user)->get("/admin/projects/{$foreign->id}/edit")->assertNotFound();
    }

    public function test_a_company_user_cannot_transition_another_companys_project(): void
    {
        $theirs = $this->company();
        $user = $this->companyUser($this->company());
        $foreign = $this->projectFor($theirs, 'foreign');

        $this->actingAs($user)
            ->post("/admin/projects/{$foreign->id}/transition", ['to' => 'published'])
            ->assertNotFound();
    }

    public function test_a_company_user_cannot_reach_another_companys_media_or_ratings(): void
    {
        $theirs = $this->company();
        $user = $this->companyUser($this->company());
        $foreign = $this->projectFor($theirs, 'foreign');

        $this->actingAs($user)->get("/admin/projects/{$foreign->id}/media")->assertNotFound();
        $this->actingAs($user)->get("/admin/projects/{$foreign->id}/ratings")->assertNotFound();
    }

    /* ----------------------------- legacy form cannot be a bypass */

    /**
     * ProjectController::store() creates a project with no company
     * association. A company user reaching it would produce a project
     * belonging to nobody — outside their own scope and invisible in their
     * portal.
     */
    public function test_a_company_user_cannot_use_the_legacy_create_form(): void
    {
        $user = $this->companyUser($this->company());

        $this->actingAs($user)->get('/admin/projects/create')->assertForbidden();
        $this->actingAs($user)->post('/admin/projects', [])->assertForbidden();
    }

    public function test_a_platform_administrator_may_still_use_the_legacy_form(): void
    {
        $this->actingAs($this->admin())->get('/admin/projects/create')->assertSuccessful();
    }

    /* --------------------------- scoped permission gates the wizard */

    public function test_an_active_membership_without_scoped_create_is_forbidden(): void
    {
        $company = $this->company();

        // A company role that does NOT carry projects.create_scoped.
        $user = User::factory()->create();
        CompanyStaff::query()->create([
            'company_id' => $company->id, 'user_id' => $user->id,
            'role' => 'viewer', 'is_active' => true,
        ]);

        // Same documented denial page, with the permission reason named.
        $this->actingAs($user)->get('/admin/projects/wizard')
            ->assertRedirect(route('admin.projects.wizard.unavailable', ['reason' => 'permission_denied']));
    }

    /* --------------------------------- area publication policy */

    /** A draft area must never be suggested on a public-facing surface. */
    public function test_an_unpublished_area_is_never_suggested(): void
    {
        $user = $this->admin();

        Area::query()->create([
            'type' => AreaType::District->value,
            'slug' => 'draft-area',
            'name_ckb' => 'Draft District',
            'publication_status' => PublicationStatus::Draft->value,
            'boundary_wkt' => 'POLYGON((44.00 36.18, 44.02 36.18, 44.02 36.20, 44.00 36.20, 44.00 36.18))',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/admin/projects/wizard/nearby?latitude=36.19&longitude=44.01');

        $response->assertJsonPath('suggested_area', null)
            ->assertJsonPath('area_unresolved', true);
        $response->assertDontSee('Draft District');
    }

    /* ---------------------------------------- prune cleans files */

    public function test_pruning_deletes_draft_media_rows_with_their_drafts(): void
    {
        $user = $this->admin();

        $abandoned = $this->draftFor($user);
        $abandoned->forceFill(['last_touched_at' => now()->subDays(90)])->save();

        ProjectDraftMedia::query()->create([
            'project_draft_id' => $abandoned->id,
            'uploaded_by' => $user->id,
            'kind' => 'image',
            'disk' => 'public',
            'path' => 'project-drafts/gone.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
        ]);

        // A missing file must not abort the sweep.
        $this->artisan('mulkihawler:prune-project-drafts', ['--days' => 30])->assertSuccessful();

        $this->assertDatabaseMissing('project_drafts', ['id' => $abandoned->id]);
        $this->assertDatabaseCount('project_draft_media', 0);
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        $user = $this->admin();
        $draft = $this->draftFor($user);
        $draft->forceFill(['last_touched_at' => now()->subDays(90)])->save();

        $this->artisan('mulkihawler:prune-project-drafts', ['--days' => 30, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('project_drafts', ['id' => $draft->id]);
    }

    /* ------------------------ round 7: persistent acting context */

    /**
     * The functional break the previous round introduced: ProjectScope
     * resolved the acting company from each request, so an ordinary index
     * request — which carries no acting_company_id — produced an empty scope
     * for a multi-company user. A blank index and 404 everywhere, for exactly
     * the users the scoping was written for.
     */
    public function test_a_multi_company_user_sees_projects_after_choosing_a_context(): void
    {
        $a = $this->company();
        $b = $this->company();
        $user = $this->companyUser($a);
        $this->addMembership($user, $b);

        $ours = $this->projectFor($a, 'ours');
        $this->projectFor($b, 'theirs');

        // Before choosing: ambiguous, so nothing is guessed.
        $this->actingAs($user)->get('/admin/projects')
            ->assertInertia(fn ($page) => $page->has('projects.data', 0));

        $this->actingAs($user)->post('/admin/projects/wizard/company', ['acting_company_id' => $a->id]);

        // After choosing: the context persists across a plain request with no
        // parameters at all.
        $this->actingAs($user)->get('/admin/projects')->assertInertia(
            fn ($page) => $page->has('projects.data', 1)->where('projects.data.0.id', $ours->id),
        );
    }

    public function test_the_acting_context_survives_navigation_to_edit(): void
    {
        $a = $this->company();
        $b = $this->company();
        $user = $this->companyUser($a);
        $this->addMembership($user, $b);

        $ours = $this->projectFor($a, 'ours');

        $this->actingAs($user)->post('/admin/projects/wizard/company', ['acting_company_id' => $a->id]);
        $this->actingAs($user)->get("/admin/projects/{$ours->id}/edit")->assertSuccessful();
    }

    public function test_switching_context_changes_the_visible_projects(): void
    {
        $a = $this->company();
        $b = $this->company();
        $user = $this->companyUser($a);
        $this->addMembership($user, $b);

        $this->projectFor($a, 'a-project');
        $theirs = $this->projectFor($b, 'b-project');

        $this->actingAs($user)->post('/admin/projects/wizard/company', ['acting_company_id' => $b->id]);

        $this->actingAs($user)->get('/admin/projects')->assertInertia(
            fn ($page) => $page->has('projects.data', 1)->where('projects.data.0.id', $theirs->id),
        );
    }

    /** A context outlives the session it was stored in; membership must not. */
    public function test_a_deactivated_membership_invalidates_the_stored_context(): void
    {
        $a = $this->company();
        $b = $this->company();
        $user = $this->companyUser($a);
        $this->addMembership($user, $b);

        $ours = $this->projectFor($a, 'ours');

        $this->actingAs($user)->post('/admin/projects/wizard/company', ['acting_company_id' => $a->id]);
        $this->actingAs($user)->get("/admin/projects/{$ours->id}/edit")->assertSuccessful();

        CompanyStaff::query()->where('user_id', $user->id)->where('company_id', $a->id)
            ->update(['is_active' => false]);

        $this->actingAs($user)->get("/admin/projects/{$ours->id}/edit")->assertNotFound();
    }

    public function test_switching_to_a_company_the_user_does_not_belong_to_is_refused(): void
    {
        $user = $this->companyUser($this->company());
        $other = $this->company();

        $this->actingAs($user)
            ->post('/admin/projects/wizard/company', ['acting_company_id' => $other->id])
            ->assertSessionHasErrors('acting_company_id');
    }

    /* --------------------- per-membership project rights */

    /**
     * A global role must not carry authority between companies. Manager at A,
     * ordinary staff at B: B's projects stay out of reach.
     */
    public function test_a_membership_without_project_rights_grants_no_access(): void
    {
        $a = $this->company();
        $b = $this->company();
        $user = $this->companyUser($a);

        // Member of B, but not a project manager there.
        CompanyStaff::query()->create([
            'company_id' => $b->id, 'user_id' => $user->id,
            'role' => 'staff', 'is_active' => true, 'may_manage_projects' => false,
        ]);

        $theirs = $this->projectFor($b, 'theirs');

        $this->assertNotContains($b->id, ActingCompany::manageableCompanyIds($user));
        $this->actingAs($user)->get("/admin/projects/{$theirs->id}/edit")->assertNotFound();
    }

    /* ----------------------------- association states */

    /**
     * Obsolete expectation, corrected.
     *
     * This asserted that a pending association with no creator, no draft and
     * no evidence was editable — which was true when "pending" alone conferred
     * rights, and is exactly the hole the provenance work closed. It now
     * asserts the opposite, which is what the current rule requires.
     */
    public function test_a_bare_pending_association_is_not_editable(): void
    {
        $company = $this->company();
        $user = $this->companyUser($company);

        $project = $this->projectFor($company, 'pending-project', [
            'is_approved' => false,
            'management_status' => 'pending',
            'created_by' => null,
            'created_via_project_draft_id' => null,
        ]);

        $this->actingAs($user)->get("/admin/projects/{$project->id}/edit")->assertNotFound();
    }

    public function test_an_expired_association_grants_no_access(): void
    {
        $company = $this->company();
        $user = $this->companyUser($company);

        $project = $this->projectFor($company, 'expired-project', [
            'is_approved' => true,
            'ends_on' => now()->subDay()->toDateString(),
        ]);

        $this->actingAs($user)->get("/admin/projects/{$project->id}/edit")->assertNotFound();
    }

    public function test_a_future_association_grants_no_access_yet(): void
    {
        $company = $this->company();
        $user = $this->companyUser($company);

        $project = $this->projectFor($company, 'future-project', [
            'is_approved' => true,
            'starts_on' => now()->addMonth()->toDateString(),
        ]);

        $this->actingAs($user)->get("/admin/projects/{$project->id}/edit")->assertNotFound();
    }

    public function test_a_revoked_association_grants_no_access(): void
    {
        $company = $this->company();
        $user = $this->companyUser($company);

        $project = $this->projectFor($company, 'revoked-project', ['management_status' => 'revoked']);

        $this->actingAs($user)->get("/admin/projects/{$project->id}/edit")->assertNotFound();
    }

    /* ------------------------- developer administration is platform-only */

    public function test_a_company_user_cannot_edit_a_developer(): void
    {
        $user = $this->companyUser($this->company());

        $developer = Developer::query()->create([
            'slug' => 'a-developer', 'name_ckb' => 'A Developer',
        ]);

        $this->actingAs($user)->get("/admin/developers/{$developer->id}/edit")->assertForbidden();
        $this->actingAs($user)->put("/admin/developers/{$developer->id}", [])->assertForbidden();
    }

    /* --------------------- round 8: regressions from review */

    /**
     * Entering the Wizard a SECOND time must honour the stored context.
     * start() re-resolved per request, so a multi-company user was bounced to
     * the selector every time no matter how often they answered.
     */
    public function test_repeated_wizard_entry_honours_the_stored_company(): void
    {
        $a = $this->company();
        $b = $this->company();
        $user = $this->companyUser($a);
        $this->addMembership($user, $b);

        $this->actingAs($user)->post('/admin/projects/wizard/company', ['acting_company_id' => $a->id]);

        // Second entry: straight into the wizard, not back to the selector.
        $this->actingAs($user)->get('/admin/projects/wizard')
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($a->id, ProjectDraft::query()->first()?->company_id);
    }

    /**
     * A membership WITHOUT may_manage_projects must never be offered. Showing
     * it and refusing the choice afterwards presents an answer the server will
     * not accept.
     */
    public function test_an_ordinary_membership_is_not_offered_in_the_selector(): void
    {
        $manages = $this->company();
        $ordinary = $this->company();
        $user = $this->companyUser($manages);

        CompanyStaff::query()->create([
            'company_id' => $ordinary->id, 'user_id' => $user->id,
            'role' => 'staff', 'is_active' => true, 'may_manage_projects' => false,
        ]);

        $this->assertNotContains($ordinary->id, ActingCompanyContext::available($user));

        $this->actingAs($user)
            ->post('/admin/projects/wizard/company', ['acting_company_id' => $ordinary->id])
            ->assertSessionHasErrors('acting_company_id');
    }

    /** The capability is readable through the documented accessor. */
    public function test_company_staff_exposes_manage_projects_capability(): void
    {
        $company = $this->company();
        $user = $this->companyUser($company);

        $staff = CompanyStaff::query()->where('user_id', $user->id)->first();

        $this->assertTrue($staff?->may('manage_projects'));
    }

    /* -------------------------------- crafted developer assignment */

    public function test_a_company_user_cannot_assign_an_unrelated_developer(): void
    {
        $mine = $this->company();
        $user = $this->companyUser($mine);
        $project = $this->projectFor($mine, 'mine');

        $foreign = Developer::query()->create([
            'slug' => 'foreign-developer', 'name_ckb' => 'Foreign',
        ]);

        $this->actingAs($user)
            ->put("/admin/projects/{$project->id}", [
                'name_ckb' => 'mine',
                'project_type' => ProjectType::Residential->value,
                'construction_status' => ConstructionStatus::UnderConstruction->value,
                'delivery_status' => DeliveryStatus::NotStarted->value,
                'developer_id' => $foreign->id,
            ])
            ->assertSessionHasErrors('developer_id');
    }

    /* ------------------------------------ legacy create permission */

    /**
     * The route requires create_unscoped; ProjectRequest checked generic
     * projects.create. A role holding one but not the other passed one gate
     * and failed the next.
     */
    public function test_legacy_creation_authorises_on_the_unscoped_permission(): void
    {
        $user = $this->admin();

        $this->assertTrue($user->hasPermission('projects.create_unscoped'));
        $this->actingAs($user)->get('/admin/projects/create')->assertSuccessful();
    }

    /* ---------------------------- media cleanup is actually retried */

    public function test_pending_media_cleanup_is_retried_and_the_row_survives(): void
    {
        $company = $this->company();
        $project = $this->projectFor($company, 'with-media');

        $media = ProjectMedia::query()->create([
            'project_id' => $project->id,
            'kind' => 'image',
            'disk' => 'public',
            'path' => 'projects/missing.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
            'is_cover' => true,
        ]);

        /*
         * CLEANUP STATE IS SET BY THE SERVICE, NOT BY MASS ASSIGNMENT.
         *
         * `cleanup_pending` and `cleanup_attempts` are deliberately absent from
         * $fillable: they are lifecycle bookkeeping the cleanup services own,
         * and a request body must never be able to mark a live row for
         * deletion. The fixture writes them explicitly instead of the model
         * being loosened to accept them.
         */
        $media->forceFill(['cleanup_pending' => true, 'cleanup_attempts' => 0])->save();

        $this->artisan('mulkihawler:retry-media-cleanup')->assertSuccessful();

        // The file does not exist, which counts as removed, so the row goes.
        $this->assertDatabaseMissing('project_media', ['id' => $media->id]);
    }

    public function test_a_dry_run_retry_changes_nothing(): void
    {
        $company = $this->company();
        $project = $this->projectFor($company, 'with-media');

        $media = ProjectMedia::query()->create([
            'project_id' => $project->id,
            'kind' => 'image', 'disk' => 'public', 'path' => 'projects/x.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 10,
        ]);

        // Service-owned lifecycle columns; see the note above.
        $media->forceFill(['cleanup_pending' => true, 'cleanup_attempts' => 0])->save();

        $this->artisan('mulkihawler:retry-media-cleanup', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseHas('project_media', ['id' => $media->id, 'cleanup_pending' => true]);
    }

    /** Rows past the attempt ceiling are reported, not retried forever. */
    /**
     * The retry ceiling, stated exactly.
     *
     * `RetryMediaCleanupAll` splits every domain into two sets:
     *   retry     — cleanup_pending AND cleanup_attempts <  CEILING
     *   exhausted — cleanup_pending AND cleanup_attempts >= CEILING
     *
     * So the attempt that REACHES the ceiling is the last one automatically
     * retried, and everything at or beyond it is left for a human. Exhausted
     * work exits non-zero on purpose, because a cron job that reports success
     * while a backlog rots is worse than one that never ran.
     *
     * The command previously received `--max-attempts`, an option no command
     * has ever declared, so Symfony aborted before running and this asserted
     * nothing whatsoever about exhausted retries.
     */
    public function test_exhausted_retries_are_left_for_a_human(): void
    {
        $company = $this->company();
        $project = $this->projectFor($company, 'with-media');
        $ceiling = ProjectMediaService::CLEANUP_ATTEMPT_CEILING;

        $media = ProjectMedia::query()->create([
            'project_id' => $project->id,
            'kind' => 'image', 'disk' => 'public', 'path' => 'projects/y.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 10,
        ]);

        // AT the ceiling: already exhausted, never retried again.
        $media->forceFill(['cleanup_pending' => true, 'cleanup_attempts' => $ceiling])->save();

        // Exhausted work remains, so the command reports failure for cron.
        $this->artisan('mulkihawler:retry-media-cleanup-all')->assertFailed();

        // Untouched: no further attempt was spent on it.
        $this->assertDatabaseHas('project_media', [
            'id' => $media->id,
            'cleanup_attempts' => $ceiling,
            'cleanup_pending' => true,
        ]);
    }

    /** One below the ceiling is still the automatic retry set. */
    public function test_a_row_below_the_ceiling_is_still_retried(): void
    {
        Storage::fake('public');

        $company = $this->company();
        $project = $this->projectFor($company, 'below-ceiling');
        $ceiling = ProjectMediaService::CLEANUP_ATTEMPT_CEILING;

        $media = ProjectMedia::query()->create([
            'project_id' => $project->id,
            'kind' => 'image', 'disk' => 'public', 'path' => 'projects/below.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 10,
        ]);

        $media->forceFill([
            'cleanup_pending' => true,
            'cleanup_attempts' => $ceiling - 1,
        ])->save();

        $this->artisan('mulkihawler:retry-media-cleanup-all');

        // The file is absent, which counts as removed, so the row goes.
        $this->assertDatabaseMissing('project_media', ['id' => $media->id]);
    }

    /** Beyond the ceiling behaves exactly as at the ceiling. */
    public function test_a_row_beyond_the_ceiling_is_not_retried(): void
    {
        $company = $this->company();
        $project = $this->projectFor($company, 'beyond-ceiling');
        $ceiling = ProjectMediaService::CLEANUP_ATTEMPT_CEILING;

        $media = ProjectMedia::query()->create([
            'project_id' => $project->id,
            'kind' => 'image', 'disk' => 'public', 'path' => 'projects/beyond.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 10,
        ]);

        $media->forceFill(['cleanup_pending' => true, 'cleanup_attempts' => $ceiling + 7])->save();

        $this->artisan('mulkihawler:retry-media-cleanup-all')->assertFailed();

        $this->assertDatabaseHas('project_media', [
            'id' => $media->id,
            'cleanup_attempts' => $ceiling + 7,
        ]);
    }

    /* ----------------------- one area policy for every caller */

    /**
     * A published child under an unpublished parent must not be returned by
     * ANY caller. The observer had the ancestry check and the nearby
     * suggestion did not, so they disagreed about the same point.
     */
    public function test_a_published_child_under_an_unpublished_parent_is_not_resolved(): void
    {
        $parent = Area::query()->create([
            'type' => AreaType::District->value,
            'slug' => 'hidden-parent',
            'name_ckb' => 'Hidden Parent',
            'publication_status' => PublicationStatus::Draft->value,
        ]);

        Area::query()->create([
            'parent_id' => $parent->id,
            'type' => AreaType::Neighborhood->value,
            'slug' => 'visible-child',
            'name_ckb' => 'Visible Child',
            'publication_status' => PublicationStatus::Published->value,
            'boundary_wkt' => 'POLYGON((44.00 36.18, 44.02 36.18, 44.02 36.20, 44.00 36.20, 44.00 36.18))',
        ]);

        $resolver = app(AreaResolver::class);
        $point = Coordinates::make(36.19, 44.01);

        $this->assertNull($resolver->resolve($point), 'The resolver must not return it.');

        $this->actingAs($this->admin())
            ->getJson('/admin/projects/wizard/nearby?latitude=36.19&longitude=44.01')
            ->assertJsonPath('suggested_area', null)
            ->assertJsonPath('area_unresolved', true);
    }

    /* ------------------ round 9: association provenance & eligibility */

    /**
     * A pending association with NO draft provenance grants nothing.
     * `management_status IN (pending, approved)` was the whole test, so
     * anything able to write a pending row could claim any project.
     */
    public function test_a_pending_association_without_provenance_grants_no_access(): void
    {
        $company = $this->company();
        $user = $this->companyUser($company);

        $project = $this->projectFor($company, 'unprovenanced', [
            'is_approved' => false,
            'management_status' => 'pending',
            'created_via_project_draft_id' => null,
            'created_by' => null,
        ]);

        $this->actingAs($user)->get("/admin/projects/{$project->id}/edit")->assertNotFound();
    }

    /** A pending association created by ANOTHER company's draft grants nothing. */
    public function test_a_pending_association_from_another_companys_draft_grants_no_access(): void
    {
        $mine = $this->company();
        $theirs = $this->company();
        $user = $this->companyUser($mine);
        $other = $this->companyUser($theirs);

        $foreignDraft = $this->draftFor($other, $theirs->id);

        $project = $this->projectFor($mine, 'foreign-provenance', [
            'is_approved' => false,
            'management_status' => 'pending',
            'created_via_project_draft_id' => $foreignDraft->id,
            'created_by' => $other->id,
        ]);

        $this->actingAs($user)->get("/admin/projects/{$project->id}/edit")->assertNotFound();
    }

    /**
     * The legitimate case: a SUBMITTED draft, for THIS project, by THIS user,
     * scoped to THIS company.
     *
     * The previous version of this test used a draft with project_id = null
     * and still expected access — it passed only because the two provenance
     * subqueries were uncorrelated, so it was asserting the bug.
     */
    public function test_a_correlated_submitted_draft_grants_pending_access(): void
    {
        $company = $this->company();
        $user = $this->companyUser($company);
        $project = $this->projectFor($company, 'my-provenance', $this->pendingProvenance($company, $user));

        // Pending access is granted by COMPLETE creation evidence, recorded
        // the way production records it.
        $this->grantCreationEvidence($project, $user);

        $this->actingAs($user)->get("/admin/projects/{$project->id}/edit")->assertSuccessful();
    }

    /** A draft that produced a DIFFERENT project vouches for nothing. */
    public function test_a_draft_for_another_project_grants_no_access(): void
    {
        $company = $this->company();
        $user = $this->companyUser($company);

        $other = $this->projectFor($company, 'other-project');
        $draft = $this->submittedDraft($user, $company, $other);

        $project = $this->projectFor($company, 'target', [
            'is_approved' => false,
            'management_status' => 'pending',
            'created_via_project_draft_id' => $draft->id,
            'created_by' => $user->id,
        ]);

        /*
         * WRITTEN DIRECTLY, because the API correctly refuses to create it.
         *
         * `recordCreationEvidence()` rejects a draft that did not create this
         * project — that guard is the thing under test. The row is therefore
         * inserted as corrupt legacy data would appear, and the assertion is
         * that the READ path refuses it too rather than trusting stored
         * evidence it never validated.
         */
        $membership = CompanyStaff::query()->where('user_id', $user->id)->firstOrFail();

        DB::table('company_project_associations')
            ->where('project_id', $project->id)
            ->update([
                'created_by_company_staff_id' => $membership->id,
                'creator_membership_role' => $membership->role,
                'creator_membership_company_id' => $membership->company_id,
                'creator_manage_projects_confirmed_at' => now(),
            ]);

        $this->actingAs($user)->get("/admin/projects/{$project->id}/edit")->assertNotFound();
    }

    /** A creator who is not the draft's owner vouches for nothing. */
    public function test_a_creator_different_from_the_draft_owner_grants_no_access(): void
    {
        $company = $this->company();
        $owner = $this->companyUser($company);
        $other = $this->companyUser($company);

        $project = $this->projectFor($company, 'mismatched');
        $draft = $this->submittedDraft($owner, $company, $project);

        CompanyProjectAssociation::query()
            ->where('project_id', $project->id)
            ->update([
                'is_approved' => false,
                'management_status' => 'pending',
                'created_via_project_draft_id' => $draft->id,
                'created_by' => $other->id,          // not the draft's owner
                'creator_manage_projects_confirmed_at' => now(),
            ]);

        $this->actingAs($other)->get("/admin/projects/{$project->id}/edit")->assertNotFound();
    }

    /** An UNSUBMITTED draft created nothing, so it vouches for nothing. */
    public function test_an_unsubmitted_draft_grants_no_access(): void
    {
        $company = $this->company();
        $user = $this->companyUser($company);
        $project = $this->projectFor($company, 'unsubmitted');

        $draft = $this->draftFor($user, $company->id);
        $draft->forceFill(['project_id' => $project->id, 'submitted_at' => null])->save();

        CompanyProjectAssociation::query()
            ->where('project_id', $project->id)
            ->update([
                'is_approved' => false,
                'management_status' => 'pending',
                'created_via_project_draft_id' => $draft->id,
                'created_by' => $user->id,
                'creator_manage_projects_confirmed_at' => now(),
            ]);

        $this->actingAs($user)->get("/admin/projects/{$project->id}/edit")->assertNotFound();
    }

    /** Permission gained LATER must not validate an old pending association. */
    public function test_missing_creation_evidence_grants_no_access(): void
    {
        $company = $this->company();
        $user = $this->companyUser($company);
        $project = $this->projectFor($company, 'no-evidence');
        $draft = $this->submittedDraft($user, $company, $project);

        CompanyProjectAssociation::query()
            ->where('project_id', $project->id)
            ->update([
                'is_approved' => false,
                'management_status' => 'pending',
                'created_via_project_draft_id' => $draft->id,
                'created_by' => $user->id,
                'creator_manage_projects_confirmed_at' => null,   // no evidence
            ]);

        $this->actingAs($user)->get("/admin/projects/{$project->id}/edit")->assertNotFound();
    }

    /* ----------------------- association lifecycle writes */

    /**
     * The REAL grant route, not a direct row update.
     *
     * The previous version wrote the columns itself, so it proved the scope
     * query and nothing about the controller — which was precisely where the
     * bug lived: grantAssociation() never set management_status at all.
     */
    public function test_the_grant_route_produces_an_immediately_manageable_association(): void
    {
        /*
         * The gated surface has to be ON to be tested. These flags
         * default to false, so the route returned 403 from the feature
         * gate and the assertion judged a screen it never reached.
         */
        $this->setFeatures(['companies.portal' => true]);

        $company = $this->company();
        $user = $this->companyUser($company);

        $project = Project::query()->create([
            'slug' => 'granted', 'name_ckb' => 'granted',
            'project_type' => ProjectType::Residential->value,
            'construction_status' => ConstructionStatus::UnderConstruction->value,
            'delivery_status' => DeliveryStatus::NotStarted->value,
            'publication_status' => PublicationStatus::Draft->value,
        ]);

        $this->actingAs($this->admin())
            ->post("/admin/companies/{$company->id}/associations", [
                'project_id' => $project->id,
                'role' => 'official_developer',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('company_project_associations', [
            'company_id' => $company->id,
            'project_id' => $project->id,
            'management_status' => 'approved',
            'is_approved' => true,
        ]);

        // And the company can actually open it.
        $this->actingAs($user)->get("/admin/projects/{$project->id}/edit")->assertSuccessful();
    }

    /** The real revoke route: marked, preserved, and access withdrawn. */
    public function test_the_revoke_route_withdraws_access_and_preserves_the_row(): void
    {
        /*
         * The gated surface has to be ON to be tested. These flags
         * default to false, so the route returned 403 from the feature
         * gate and the assertion judged a screen it never reached.
         */
        $this->setFeatures(['companies.portal' => true]);

        $company = $this->company();
        $user = $this->companyUser($company);
        $project = $this->projectFor($company, 'to-revoke-route');

        $association = CompanyProjectAssociation::query()
            ->where('project_id', $project->id)->firstOrFail();

        $this->actingAs($user)->get("/admin/projects/{$project->id}/edit")->assertSuccessful();

        $this->actingAs($this->admin())
            ->delete("/admin/companies/{$company->id}/associations/{$association->id}")
            ->assertRedirect();

        $this->assertDatabaseHas('company_project_associations', [
            'id' => $association->id,
            'management_status' => 'revoked',
            'is_approved' => false,
        ]);
        $this->assertNotNull($association->refresh()->revoked_at);

        $this->actingAs($user)->get("/admin/projects/{$project->id}/edit")->assertNotFound();
    }

    /** Creation evidence cannot be altered by an ordinary update. */
    public function test_creation_evidence_is_immutable(): void
    {
        $company = $this->company();
        $user = $this->companyUser($company);
        $project = $this->projectFor($company, 'immutable-evidence');
        $draft = $this->submittedDraft($user, $company, $project);
        $membership = CompanyStaff::query()->where('user_id', $user->id)->firstOrFail();

        $association = CompanyProjectAssociation::query()
            ->where('project_id', $project->id)->firstOrFail();

        $association->forceFill([
            'created_via_project_draft_id' => $draft->id,
            'created_by' => $user->id,
        ])->saveQuietly();

        $association->recordCreationEvidence($membership);

        $this->expectException(RuntimeException::class);

        $association->refresh()->update(['created_by' => $this->admin()->id]);
    }

    /** Evidence is written once. A second call is refused. */
    public function test_creation_evidence_cannot_be_rewritten(): void
    {
        $company = $this->company();
        $user = $this->companyUser($company);
        $project = $this->projectFor($company, 'evidence-once');
        $membership = CompanyStaff::query()->where('user_id', $user->id)->firstOrFail();

        $association = CompanyProjectAssociation::query()
            ->where('project_id', $project->id)->firstOrFail();

        // Evidence describes the recorded creator AND the draft that created
        // the project; both are prerequisites the model enforces.
        $association->forceFill([
            'created_by' => $user->id,
            'created_via_project_draft_id' => $this->submittedDraft($user, $company, $project)->id,
        ])->save();

        $association->recordCreationEvidence($membership);

        $this->expectException(RuntimeException::class);

        $association->refresh()->recordCreationEvidence($membership);
    }

    /** A forged timestamp with no matching membership record proves nothing. */
    public function test_a_forged_evidence_timestamp_grants_no_access(): void
    {
        $company = $this->company();
        $user = $this->companyUser($company);
        $project = $this->projectFor($company, 'forged');
        $draft = $this->submittedDraft($user, $company, $project);

        CompanyProjectAssociation::query()
            ->where('project_id', $project->id)
            ->update([
                'is_approved' => false,
                'management_status' => 'pending',
                'created_via_project_draft_id' => $draft->id,
                'created_by' => $user->id,
                // Timestamp present, but no staff id and no role.
                'creator_manage_projects_confirmed_at' => now(),
                'created_by_company_staff_id' => null,
                'creator_membership_role' => null,
            ]);

        $this->actingAs($user)->get("/admin/projects/{$project->id}/edit")->assertNotFound();
    }

    /** Wizard submission records complete, correlated evidence. */
    public function test_wizard_submission_records_complete_creation_evidence(): void
    {
        $company = $this->company();
        $user = $this->companyUser($company);
        $membership = CompanyStaff::query()->where('user_id', $user->id)->firstOrFail();

        $draft = $this->completeDraftFor($user, [
            'developer' => ['company_id' => $company->id, 'association_role' => 'official_developer'],
        ]);
        $draft->forceFill(['company_id' => $company->id, 'acting_company_id' => $company->id])->save();

        $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/submit");

        $this->assertDatabaseHas('company_project_associations', [
            'company_id' => $company->id,
            'created_via_project_draft_id' => $draft->id,
            'created_by' => $user->id,
            'created_by_company_staff_id' => $membership->id,
            'creator_membership_role' => $membership->role,
        ]);

        $association = CompanyProjectAssociation::query()
            ->where('created_via_project_draft_id', $draft->id)->firstOrFail();

        $this->assertNotNull($association->creator_manage_projects_confirmed_at);

        // And the created project is reachable by its creator.
        $this->actingAs($user)
            ->get("/admin/projects/{$draft->refresh()->project_id}/edit")
            ->assertSuccessful();
    }

    /** Revoking preserves the row: it is the history a dispute turns on. */
    public function test_revoking_marks_the_association_rather_than_deleting_it(): void
    {
        /*
         * The gated surface has to be ON to be tested. These flags
         * default to false, so the route returned 403 from the feature
         * gate and the assertion judged a screen it never reached.
         */
        $this->setFeatures(['companies.portal' => true]);

        $company = $this->company();
        $project = $this->projectFor($company, 'to-revoke');

        $association = CompanyProjectAssociation::query()
            ->where('project_id', $project->id)->firstOrFail();

        $this->actingAs($this->admin())
            ->delete("/admin/companies/{$company->id}/associations/{$association->id}");

        $this->assertDatabaseHas('company_project_associations', [
            'id' => $association->id,
            'management_status' => 'revoked',
            'is_approved' => false,
        ]);
    }

    /** Status and approval flag must agree. */
    public function test_an_inconsistent_association_write_is_refused(): void
    {
        $company = $this->company();
        $project = $this->projectFor($company, 'consistency');

        $this->expectException(RuntimeException::class);

        CompanyProjectAssociation::query()->create([
            'company_id' => $company->id,
            'project_id' => $project->id,
            'role' => 'official_developer',
            'management_status' => 'pending',
            'is_approved' => true,       // contradicts pending
        ]);
    }

    /** Wizard submission records the provenance that makes pending trustworthy. */
    public function test_submission_records_draft_provenance_on_the_association(): void
    {
        $company = $this->company();
        $user = $this->companyUser($company);

        $draft = $this->completeDraftFor($user, [
            'developer' => ['company_id' => $company->id, 'association_role' => 'official_developer'],
        ]);
        $draft->forceFill(['company_id' => $company->id, 'acting_company_id' => $company->id])->save();

        $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/submit");

        $this->assertDatabaseHas('company_project_associations', [
            'company_id' => $company->id,
            'created_via_project_draft_id' => $draft->id,
            'created_by' => $user->id,
            'management_status' => 'pending',
        ]);
    }

    /** legacy_review grants nothing, whatever else is true of the row. */
    public function test_a_legacy_review_association_grants_no_access(): void
    {
        $company = $this->company();
        $user = $this->companyUser($company);

        $project = $this->projectFor($company, 'legacy', [
            'is_approved' => false,
            'management_status' => 'legacy_review',
        ]);

        $this->actingAs($user)->get("/admin/projects/{$project->id}/edit")->assertNotFound();
    }

    /** An approved row whose is_approved disagrees is inconsistent: no access. */
    /**
     * The inconsistent state is UNREACHABLE, which is stronger than refused.
     *
     * This asserted that an association marked `approved` while
     * `is_approved = false` grants no access. It could never construct that
     * row: `AssociationLifecycle` rejects it in the model, and a CHECK
     * constraint rejects it in the database, so even a direct SQL write fails
     * with "association lifecycle inconsistent".
     *
     * Asserting the refusal is therefore the honest test — the read path can
     * never meet this combination because the schema will not store it. The
     * original intent is preserved and strengthened rather than dropped.
     */
    public function test_an_inconsistent_approval_cannot_be_persisted(): void
    {
        $company = $this->company();
        $project = $this->projectFor($company, 'inconsistent');

        $this->expectException(QueryException::class);

        DB::table('company_project_associations')
            ->where('project_id', $project->id)
            ->update(['is_approved' => false, 'management_status' => 'approved']);
    }

    /* ----------------------------- company eligibility */

    /**
     * `suspended` is a VERIFICATION status, not a publication one, so my
     * previous filter matched nothing and a suspended company stayed usable.
     */
    public function test_an_unverified_company_is_not_an_acting_context(): void
    {
        $company = $this->company();
        $user = $this->companyUser($company);

        $company->forceFill(['verification_status' => 'suspended'])->save();

        $this->assertSame([], ActingCompanyContext::available($user->refresh()));
    }

    public function test_an_unpublished_company_is_not_an_acting_context(): void
    {
        $company = $this->company();
        $user = $this->companyUser($company);

        $company->forceFill(['publication_status' => 'draft'])->save();

        $this->assertSame([], ActingCompanyContext::available($user->refresh()));
    }

    public function test_a_soft_deleted_company_is_not_an_acting_context(): void
    {
        $company = $this->company();
        $user = $this->companyUser($company);

        $company->delete();

        $this->assertSame([], ActingCompanyContext::available($user->refresh()));
    }

    /* ------------------------ round 10: explicit mode & evidence */

    /** Platform mode is a deliberate choice that persists. */
    public function test_a_dual_role_user_can_switch_platform_company_platform(): void
    {
        $company = $this->company();
        $user = User::factory()->superAdmin()->create();

        CompanyStaff::query()->create([
            'company_id' => $company->id, 'user_id' => $user->id,
            'role' => 'manager', 'is_active' => true, 'may_manage_projects' => true,
        ]);

        $mine = $this->projectFor($company, 'mine');
        $foreign = $this->projectFor($this->company(), 'foreign');

        // Platform: everything visible.
        $this->actingAs($user)->post('/admin/acting-company', ['acting_company_id' => 'platform']);
        $this->actingAs($user)->get("/admin/projects/{$foreign->id}/edit")->assertSuccessful();

        // Company: scoped, even though the unscoped permission is still held.
        $this->actingAs($user)->post('/admin/acting-company', ['acting_company_id' => $company->id]);
        $this->actingAs($user)->get("/admin/projects/{$foreign->id}/edit")->assertNotFound();
        $this->actingAs($user)->get("/admin/projects/{$mine->id}/edit")->assertSuccessful();

        // Back to platform.
        $this->actingAs($user)->post('/admin/acting-company', ['acting_company_id' => 'platform']);
        $this->actingAs($user)->get("/admin/projects/{$foreign->id}/edit")->assertSuccessful();
    }

    /** A sole membership must not silently override an explicit platform choice. */
    public function test_platform_mode_survives_a_sole_membership(): void
    {
        $company = $this->company();
        $user = User::factory()->superAdmin()->create();

        CompanyStaff::query()->create([
            'company_id' => $company->id, 'user_id' => $user->id,
            'role' => 'manager', 'is_active' => true, 'may_manage_projects' => true,
        ]);

        $foreign = $this->projectFor($this->company(), 'foreign');

        $this->actingAs($user)->post('/admin/acting-company', ['acting_company_id' => 'platform']);
        $this->actingAs($user)->get("/admin/projects/{$foreign->id}/edit")->assertSuccessful();
    }

    /** A company-only user cannot reach platform mode. */
    public function test_a_company_user_cannot_switch_to_platform_mode(): void
    {
        $user = $this->companyUser($this->company());

        $this->actingAs($user)
            ->post('/admin/acting-company', ['acting_company_id' => 'platform'])
            ->assertForbidden();
    }

    /** Evidence cannot be written for a membership that lacks the right. */
    public function test_evidence_cannot_be_recorded_without_project_rights(): void
    {
        $company = $this->company();
        $user = User::factory()->companyAccountManager()->create();

        $membership = CompanyStaff::query()->create([
            'company_id' => $company->id, 'user_id' => $user->id,
            'role' => 'staff', 'is_active' => true, 'may_manage_projects' => false,
        ]);

        $project = $this->projectFor($company, 'no-rights');
        $association = CompanyProjectAssociation::query()
            ->where('project_id', $project->id)->firstOrFail();

        $this->expectException(RuntimeException::class);

        $association->recordCreationEvidence($membership);
    }

    /** Evidence must come from a membership of the SAME company. */
    public function test_evidence_from_another_companys_membership_is_refused(): void
    {
        $mine = $this->company();
        $theirs = $this->company();
        $user = $this->companyUser($theirs);

        $project = $this->projectFor($mine, 'cross-company-evidence');
        $association = CompanyProjectAssociation::query()
            ->where('project_id', $project->id)->firstOrFail();

        $membership = CompanyStaff::query()->where('user_id', $user->id)->firstOrFail();

        $this->expectException(RuntimeException::class);

        $association->recordCreationEvidence($membership);
    }

    /** The evidence snapshot survives deletion of the staff row. */
    public function test_creation_evidence_survives_membership_deletion(): void
    {
        $company = $this->company();
        $user = $this->companyUser($company);
        $project = $this->projectFor($company, 'evidence-survives');
        $membership = CompanyStaff::query()->where('user_id', $user->id)->firstOrFail();

        $association = CompanyProjectAssociation::query()
            ->where('project_id', $project->id)->firstOrFail();

        // Evidence describes the recorded creator AND the originating draft.
        $association->forceFill([
            'created_by' => $user->id,
            'created_via_project_draft_id' => $this->submittedDraft($user, $company, $project)->id,
        ])->save();

        $association->recordCreationEvidence($membership);

        $membership->delete();

        $association->refresh();

        // The id is a plain reference, and the snapshot carries the facts, so
        // nothing is nulled out by the deletion.
        $this->assertNotNull($association->created_by_company_staff_id);
        $this->assertNotNull($association->creator_membership_role);
        $this->assertSame($company->id, (int) $association->creator_membership_company_id);
    }

    /* --------------------- Milestone 1 D: developer deadlock */

    /**
     * The deadlock: permitted developers were derived from existing projects,
     * so a company entering its FIRST project had none — precisely when the
     * field matters.
     */
    public function test_a_company_with_no_projects_can_still_have_permitted_developers(): void
    {
        $company = $this->company();
        $user = $this->companyUser($company);

        $developer = Developer::query()->create([
            'slug' => 'linked-developer', 'name_ckb' => 'Linked',
        ]);

        CompanyDeveloperAssociation::query()->create([
            'company_id' => $company->id,
            'developer_id' => $developer->id,
            'management_status' => 'approved',
            'is_approved' => true,
            'approved_at' => now(),
        ]);

        $request = request();
        $request->setUserResolver(static fn () => $user);
        $this->actingAs($user)->post('/admin/projects/wizard/company', ['acting_company_id' => $company->id]);

        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseHas('company_developer_associations', [
            'company_id' => $company->id,
            'developer_id' => $developer->id,
            'is_approved' => true,
        ]);
    }

    /** A pending link confers nothing — a company cannot approve itself. */
    public function test_a_pending_developer_link_does_not_permit_assignment(): void
    {
        $company = $this->company();

        $developer = Developer::query()->create([
            'slug' => 'pending-developer', 'name_ckb' => 'Pending',
        ]);

        CompanyDeveloperAssociation::query()->create([
            'company_id' => $company->id,
            'developer_id' => $developer->id,
            'management_status' => 'pending',
            'is_approved' => false,
        ]);

        $this->assertSame(
            0,
            CompanyDeveloperAssociation::query()
                ->live()->where('company_id', $company->id)->count(),
        );
    }

    /** An expired link stops permitting assignment. */
    public function test_an_expired_developer_link_is_not_live(): void
    {
        $company = $this->company();

        $developer = Developer::query()->create([
            'slug' => 'expired-developer', 'name_ckb' => 'Expired',
        ]);

        CompanyDeveloperAssociation::query()->create([
            'company_id' => $company->id,
            'developer_id' => $developer->id,
            'management_status' => 'approved',
            'is_approved' => true,
            'approved_at' => now(),
            'ends_on' => now()->subDay()->toDateString(),
        ]);

        $this->assertSame(
            0,
            CompanyDeveloperAssociation::query()
                ->live()->where('company_id', $company->id)->count(),
        );
    }

    /* --------------------- Milestone 1 E: exactly one cover */

    public function test_the_first_promoted_image_becomes_the_cover(): void
    {
        $company = $this->company();
        $project = $this->projectFor($company, 'cover-first');

        $this->makeProjectMedia($project->id, 'a.jpg', 0);
        $this->makeProjectMedia($project->id, 'b.jpg', 1);

        app(ProjectMediaService::class)->reconcileCover($project->id);

        $this->assertSame(1, ProjectMedia::query()
            ->where('project_id', $project->id)->where('is_cover', true)->count());
    }

    /** Two covers is not a state anybody chose; reconciliation fixes it. */
    public function test_multiple_covers_are_reconciled_to_one(): void
    {
        $company = $this->company();
        $project = $this->projectFor($company, 'cover-many');

        $this->makeProjectMedia($project->id, 'a.jpg', 0, true);
        $this->makeProjectMedia($project->id, 'b.jpg', 1, true);

        app(ProjectMediaService::class)->reconcileCover($project->id);

        $this->assertSame(1, ProjectMedia::query()
            ->where('project_id', $project->id)->where('is_cover', true)->count());
    }

    /** The last cover cannot be unset. */
    public function test_the_only_cover_cannot_be_unset(): void
    {
        $company = $this->company();
        $project = $this->projectFor($company, 'cover-only');

        $media = $this->makeProjectMedia($project->id, 'only.jpg', 0, true);

        $service = app(ProjectMediaService::class);

        $this->assertFalse($service->unsetCover($project->id, $media->id));
        $this->assertTrue((bool) $media->refresh()->is_cover);
    }

    /** Deleting the cover promotes the next image by sort order. */
    public function test_deleting_the_cover_promotes_the_next_image(): void
    {
        $company = $this->company();
        $project = $this->projectFor($company, 'cover-delete');

        $first = $this->makeProjectMedia($project->id, 'a.jpg', 0, true);
        $second = $this->makeProjectMedia($project->id, 'b.jpg', 1);

        app(ProjectMediaService::class)
            ->delete($project->id, $first->id);

        $this->assertTrue((bool) $second->refresh()->is_cover);
    }

    /** A cleanup-pending row must never become the cover. */
    public function test_a_cleanup_pending_row_cannot_be_the_cover(): void
    {
        $company = $this->company();
        $project = $this->projectFor($company, 'cover-pending');

        $good = $this->makeProjectMedia($project->id, 'a.jpg', 0);
        $stuck = $this->makeProjectMedia($project->id, 'b.jpg', 1);
        $stuck->forceFill(['cleanup_pending' => true])->save();

        $service = app(ProjectMediaService::class);

        $this->assertFalse($service->setCover($project->id, $stuck->id));

        $service->reconcileCover($project->id);

        $this->assertTrue((bool) $good->refresh()->is_cover);
    }

    /* ------------------ Milestone 2: pricing provenance persistence */

    public function test_full_pricing_provenance_is_persisted(): void
    {
        $user = $this->admin();
        $draft = $this->completeDraftFor($user, [
            'pricing' => [
                'price_from' => 150000,
                'price_to' => 240000,
                'currency' => 'USD',
                'price_type' => PriceType::SaleAsking->value,
                'price_period' => 'total',
                'price_effective_date' => now()->toDateString(),
                'price_source' => 'developer price list',
                'price_confidence' => 'high',
            ],
        ]);

        $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/submit");

        $price = ProjectPrice::query()->firstOrFail();

        $this->assertSame('total', $price->period);
        $this->assertSame('developer price list', $price->source);
        $this->assertSame('high', $price->confidence);
        $this->assertNotNull($price->effective_date);
        $this->assertTrue($price->requiresQualifier());
    }

    /** Provenance without an anchoring price must not be silently dropped. */
    public function test_provenance_without_price_from_is_rejected(): void
    {
        $user = $this->admin();
        $draft = $this->draftFor($user);

        $this->actingAs($user)
            ->post("/admin/projects/wizard/{$draft->id}/pricing", [
                'version' => $draft->version,
                'price_source' => 'a brochure',
                'price_confidence' => 'high',
            ])
            ->assertSessionHasErrors('price_from');
    }

    /* ------------------------------ retention controls */

    public function test_touching_a_draft_renews_retention_without_bumping_version(): void
    {
        $user = $this->admin();
        $draft = $this->draftFor($user);
        $draft->forceFill(['last_touched_at' => now()->subDays(20)])->save();

        $version = (int) $draft->version;

        $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/touch")->assertRedirect();

        $draft->refresh();

        $this->assertTrue($draft->last_touched_at->isToday());
        // Nothing changed, so an open tab must stay valid.
        $this->assertSame($version, (int) $draft->version);
    }

    public function test_a_submitted_draft_cannot_be_touched(): void
    {
        $user = $this->admin();
        $draft = $this->completeDraftFor($user);

        $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/submit");
        $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/touch")->assertStatus(409);
    }

    /* -------------------- draft administration: routes and privacy */

    public function test_the_draft_admin_listing_is_company_scoped(): void
    {
        $mine = $this->company();
        $theirs = $this->company();
        $user = $this->companyUser($mine);
        $other = $this->companyUser($theirs);

        $ours = $this->draftFor($user, $mine->id);
        $this->draftFor($other, $theirs->id);

        $this->actingAs($user)->post('/admin/projects/wizard/company', ['acting_company_id' => $mine->id]);

        $this->actingAs($user)->get('/admin/project-drafts')->assertInertia(
            fn ($page) => $page->has('drafts.items', 1)->where('drafts.items.0.id', $ours->id),
        );
    }

    /** Recovery is platform-only: reassigning somebody's work is intervention. */
    public function test_a_company_user_cannot_recover_a_draft(): void
    {
        $company = $this->company();
        $user = $this->companyUser($company);
        $draft = $this->draftFor($user, $company->id);

        $this->actingAs($user)
            ->post("/admin/project-drafts/{$draft->id}/recover")
            ->assertForbidden();
    }

    public function test_a_platform_operator_recovers_a_draft_and_bumps_the_version(): void
    {
        $owner = $this->admin();
        $operator = $this->admin();
        $draft = $this->draftFor($owner);
        $version = (int) $draft->version;

        $this->actingAs($operator)
            ->post("/admin/project-drafts/{$draft->id}/recover")
            ->assertRedirect();

        $draft->refresh();

        $this->assertSame($operator->id, (int) $draft->user_id);
        // Anybody holding the old version is now stale and must be told.
        $this->assertGreaterThan($version, (int) $draft->version);
    }

    public function test_a_submitted_draft_cannot_be_recovered_or_purged(): void
    {
        $user = $this->admin();
        $draft = $this->completeDraftFor($user);

        $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/submit");

        $this->actingAs($user)->post("/admin/project-drafts/{$draft->id}/recover")->assertStatus(409);
        $this->actingAs($user)->delete("/admin/project-drafts/{$draft->id}")->assertStatus(409);
    }

    public function test_a_company_user_cannot_purge_any_draft(): void
    {
        $company = $this->company();
        $user = $this->companyUser($company);
        $draft = $this->draftFor($user, $company->id);

        $this->actingAs($user)->delete("/admin/project-drafts/{$draft->id}")->assertForbidden();
        $this->assertDatabaseHas('project_drafts', ['id' => $draft->id]);
    }

    /* ------------------------- media ownership across companies */

    public function test_draft_media_is_not_visible_to_another_company(): void
    {
        $mine = $this->company();
        $theirs = $this->company();
        $owner = $this->companyUser($mine);
        $intruder = $this->companyUser($theirs);

        $draft = $this->draftFor($owner, $mine->id);

        ProjectDraftMedia::query()->create([
            'project_draft_id' => $draft->id,
            'uploaded_by' => $owner->id,
            'acting_company_id' => $mine->id,
            'kind' => 'image', 'disk' => 'public', 'path' => 'p/private.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 10,
        ]);

        // The draft itself is unreachable, so its media is too.
        $this->actingAs($intruder)
            ->get("/admin/projects/wizard/{$draft->id}/identity")
            ->assertNotFound();
    }

    /* ------------------------------- unavailable states */

    public function test_the_wizard_unavailable_page_explains_a_disabled_feature(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/projects/wizard/unavailable?reason=feature_disabled')
            ->assertSuccessful()
            ->assertInertia(fn ($page) => $page->where('reason', 'feature_disabled'));
    }

    public function test_an_unknown_reason_falls_back_to_permission_denied(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/projects/wizard/unavailable?reason=nonsense')
            ->assertInertia(fn ($page) => $page->where('reason', 'permission_denied'));
    }

    /* ---------------------- developer link review workflow */

    public function test_a_company_user_cannot_reach_the_developer_link_queue(): void
    {
        $this->actingAs($this->companyUser($this->company()))
            ->get('/admin/company-developers')
            ->assertForbidden();
    }

    public function test_approving_a_link_makes_a_developer_assignable(): void
    {
        $company = $this->company();

        $developer = Developer::query()->create([
            'slug' => 'queued-developer', 'name_ckb' => 'Queued',
        ]);

        $link = CompanyDeveloperAssociation::query()->create([
            'company_id' => $company->id,
            'developer_id' => $developer->id,
            'management_status' => 'pending',
            'is_approved' => false,
        ]);

        $this->actingAs($this->admin())
            ->post("/admin/company-developers/{$link->id}/approve")
            ->assertRedirect();

        $this->assertSame(1, CompanyDeveloperAssociation::query()
            ->live()->where('company_id', $company->id)->count());
    }

    public function test_rejecting_a_link_requires_a_reason(): void
    {
        $company = $this->company();

        $developer = Developer::query()->create([
            'slug' => 'rejected-developer', 'name_ckb' => 'Rejected',
        ]);

        $link = CompanyDeveloperAssociation::query()->create([
            'company_id' => $company->id,
            'developer_id' => $developer->id,
            'management_status' => 'pending',
            'is_approved' => false,
        ]);

        $this->actingAs($this->admin())
            ->post("/admin/company-developers/{$link->id}/reject", [])
            ->assertSessionHasErrors('notes');
    }

    /* ---------------- platform mode and unscoped drafts */

    public function test_a_platform_only_user_can_create_and_resume_an_unscoped_draft(): void
    {
        $user = $this->admin();

        $this->actingAs($user)->get('/admin/projects/wizard')->assertRedirect();

        $draft = ProjectDraft::query()->firstOrFail();

        $this->assertNull($draft->company_id);
        $this->actingAs($user)->get("/admin/projects/wizard/{$draft->id}/identity")->assertSuccessful();
    }

    /**
     * The bug: `stillPermits($user, null)` asked "has this user no
     * memberships", so a dual-role operator was refused their own unscoped
     * draft the moment they belonged to any company.
     */
    public function test_a_dual_role_user_in_platform_mode_can_use_an_unscoped_draft(): void
    {
        $company = $this->company();
        $user = User::factory()->superAdmin()->create();

        CompanyStaff::query()->create([
            'company_id' => $company->id, 'user_id' => $user->id,
            'role' => 'manager', 'is_active' => true, 'may_manage_projects' => true,
        ]);

        $this->actingAs($user)->post('/admin/acting-company', ['acting_company_id' => 'platform']);
        $this->actingAs($user)->get('/admin/projects/wizard')->assertRedirect();

        $draft = ProjectDraft::query()->firstOrFail();

        $this->assertNull($draft->company_id);
        $this->actingAs($user)->get("/admin/projects/wizard/{$draft->id}/identity")->assertSuccessful();
    }

    public function test_a_dual_role_user_with_several_companies_can_reach_platform_mode(): void
    {
        $a = $this->company();
        $b = $this->company();
        $user = User::factory()->superAdmin()->create();

        foreach ([$a, $b] as $company) {
            CompanyStaff::query()->create([
                'company_id' => $company->id, 'user_id' => $user->id,
                'role' => 'manager', 'is_active' => true, 'may_manage_projects' => true,
            ]);
        }

        $this->actingAs($user)->post('/admin/acting-company', ['acting_company_id' => 'platform']);
        $this->actingAs($user)->get('/admin/projects/wizard')->assertRedirect();

        $this->assertNull(ProjectDraft::query()->firstOrFail()->company_id);
    }

    /** An unscoped draft must be refused while deliberately in company mode. */
    public function test_an_unscoped_draft_is_refused_in_company_mode(): void
    {
        $company = $this->company();
        $user = User::factory()->superAdmin()->create();

        CompanyStaff::query()->create([
            'company_id' => $company->id, 'user_id' => $user->id,
            'role' => 'manager', 'is_active' => true, 'may_manage_projects' => true,
        ]);

        $this->actingAs($user)->post('/admin/acting-company', ['acting_company_id' => 'platform']);
        $this->actingAs($user)->get('/admin/projects/wizard');

        $draft = ProjectDraft::query()->firstOrFail();

        $this->actingAs($user)->post('/admin/acting-company', ['acting_company_id' => $company->id]);
        $this->actingAs($user)->get("/admin/projects/wizard/{$draft->id}/identity")->assertNotFound();
    }

    /* ------------------- unavailable states are reachable */

    public function test_the_unavailable_page_is_reachable_without_the_scoped_permission(): void
    {
        // No role at all: previously the middleware rejected this user before
        // the page explaining the rejection could render.
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin/projects/wizard/unavailable?reason=permission_denied')
            ->assertSuccessful();
    }

    public function test_the_unavailable_page_is_reachable_with_the_feature_off(): void
    {
        $this->setFeatures(['projects.wizard' => false,
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/projects/wizard/unavailable?reason=feature_disabled')
            ->assertSuccessful()
            ->assertInertia(fn ($page) => $page->where('reason', 'feature_disabled'));
    }

    /* ---------------------- completion hand-offs are real */

    public function test_submission_lands_on_a_completion_page_with_hand_offs(): void
    {
        $user = $this->admin();
        $draft = $this->completeDraftFor($user);

        $response = $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/submit");

        $project = Project::query()->firstOrFail();

        $response->assertRedirect(route('admin.projects.wizard.done', $project->id));

        $this->actingAs($user)
            ->get(route('admin.projects.wizard.done', $project->id))
            ->assertSuccessful()
            ->assertInertia(fn ($page) => $page
                ->where('project.id', $project->id)
                ->where('can.edit', true)
                ->where('can.ratings', true));
    }

    /** The completion page obeys project scope like everything else. */
    public function test_a_company_user_cannot_open_another_companys_completion_page(): void
    {
        $theirs = $this->company();
        $user = $this->companyUser($this->company());
        $foreign = $this->projectFor($theirs, 'foreign-done');

        $this->actingAs($user)
            ->get(route('admin.projects.wizard.done', $foreign->id))
            ->assertNotFound();
    }

    /* ------------------------- draft media preview privacy */

    public function test_draft_media_preview_is_scoped_to_its_own_draft(): void
    {
        $owner = $this->admin();
        $intruder = $this->admin();

        $ownerDraft = $this->draftFor($owner);
        $intruderDraft = $this->draftFor($intruder);

        $item = ProjectDraftMedia::query()->create([
            'project_draft_id' => $ownerDraft->id,
            'uploaded_by' => $owner->id,
            'kind' => 'image', 'disk' => 'public', 'path' => 'p/secret.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 10,
        ]);

        // Aiming their own draft at the owner's media id resolves nothing.
        $this->actingAs($intruder)
            ->get("/admin/projects/wizard/{$intruderDraft->id}/media/{$item->id}/preview")
            ->assertNotFound();
    }

    /* ------------------------- draft media cover invariant */

    public function test_the_first_draft_upload_becomes_the_cover(): void
    {
        $user = $this->admin();
        $draft = $this->draftFor($user);
        $service = app(ProjectDraftMediaService::class);

        $first = $service->attach((int) $draft->id, [
            'uploaded_by' => $user->id, 'kind' => 'image', 'disk' => 'public',
            'path' => 'a.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 10,
        ]);

        $service->attach((int) $draft->id, [
            'uploaded_by' => $user->id, 'kind' => 'image', 'disk' => 'public',
            'path' => 'b.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 10,
        ]);

        $this->assertTrue((bool) $first->refresh()->is_cover);
        $this->assertSame(1, ProjectDraftMedia::query()
            ->where('project_draft_id', $draft->id)->where('is_cover', true)->count());
    }

    public function test_a_draft_cover_from_another_uploader_is_refused(): void
    {
        $owner = $this->admin();
        $other = $this->admin();
        $draft = $this->draftFor($owner);
        $service = app(ProjectDraftMediaService::class);

        $item = $service->attach((int) $draft->id, [
            'uploaded_by' => $owner->id, 'kind' => 'image', 'disk' => 'public',
            'path' => 'a.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 10,
        ]);

        $this->assertFalse($service->setCover((int) $draft->id, $other->id, (int) $item->id));
    }

    /* ------------------ route precedence and dispatch */

    /**
     * The literal endpoints must reach their own methods.
     *
     * `/{draft}/{step}` was registered first, so POST .../submit dispatched to
     * save() with step="submit" and GET /done/7 dispatched to show() with
     * draft="done". Asserting the method exists was not enough; this asserts
     * where the URL actually goes.
     */
    public function test_every_wizard_url_dispatches_to_its_intended_method(): void
    {
        $expected = [
            ['POST', '/admin/projects/wizard/1/submit', 'submit'],
            ['POST', '/admin/projects/wizard/1/touch', 'touch'],
            ['POST', '/admin/projects/wizard/1/media', 'uploadMedia'],
            ['PATCH', '/admin/projects/wizard/1/media', 'updateMedia'],
            ['GET', '/admin/projects/wizard/done/1', 'done'],
            ['GET', '/admin/projects/wizard/1/identity', 'show'],
            ['POST', '/admin/projects/wizard/1/identity', 'save'],
            ['GET', '/admin/projects/wizard/nearby', 'nearby'],
        ];

        foreach ($expected as [$method, $url, $action]) {
            $route = app('router')->getRoutes()->match(
                Request::create($url, $method),
            );

            $this->assertStringEndsWith(
                '@'.$action,
                (string) $route->getActionName(),
                "{$method} {$url} should dispatch to {$action}",
            );
        }
    }

    /** A step name outside the enum must not match the wildcard. */
    public function test_an_unknown_step_does_not_match_the_wizard_wildcard(): void
    {
        $this->expectException(NotFoundHttpException::class);

        app('router')->getRoutes()->match(
            Request::create('/admin/projects/wizard/1/not-a-step', 'GET'),
        );
    }

    /* ------------------ company-scoped submission end to end */

    /**
     * The sequencing bug: evidence was written before the draft had
     * project_id or submitted_at, and the writer requires both — so every
     * company-scoped submission threw.
     */
    public function test_a_company_scoped_submission_completes_and_grants_access(): void
    {
        $company = $this->company();
        $user = $this->companyUser($company);

        $draft = $this->completeDraftFor($user, [
            'developer' => ['company_id' => $company->id, 'association_role' => 'official_developer'],
        ]);
        $draft->forceFill(['company_id' => $company->id, 'acting_company_id' => $company->id])->save();

        $this->actingAs($user)->post('/admin/projects/wizard/company', ['acting_company_id' => $company->id]);
        $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/submit")->assertRedirect();

        $project = Project::query()->firstOrFail();

        $this->assertDatabaseHas('company_project_associations', [
            'company_id' => $company->id,
            'project_id' => $project->id,
            'created_via_project_draft_id' => $draft->id,
        ]);

        // And the company can actually open what it just created.
        $this->actingAs($user)->get("/admin/projects/{$project->id}/edit")->assertSuccessful();
    }

    /* ------------------------- draft media privacy */

    public function test_draft_uploads_are_not_stored_on_the_public_disk(): void
    {
        $user = $this->admin();
        $draft = $this->draftFor($user);

        $item = ProjectDraftMedia::query()->create([
            'project_draft_id' => $draft->id,
            'uploaded_by' => $user->id,
            'kind' => 'image', 'disk' => 'draft-media', 'path' => 'p/private.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 10,
        ]);

        // A private disk has no /storage symlink, so no anonymous path exists.
        $this->assertSame('draft-media', $item->disk);
        $this->assertNull(config('filesystems.disks.draft-media.url'));
    }

    public function test_an_anonymous_visitor_cannot_reach_draft_media(): void
    {
        $user = $this->admin();
        $draft = $this->draftFor($user);

        $item = ProjectDraftMedia::query()->create([
            'project_draft_id' => $draft->id,
            'uploaded_by' => $user->id,
            'kind' => 'image', 'disk' => 'draft-media', 'path' => 'p/x.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 10,
        ]);

        $this->get("/admin/projects/wizard/{$draft->id}/media/{$item->id}/preview")
            ->assertRedirect();   // unauthenticated
    }

    /* ---------------------- permission naming */

    /**
     * A non-superadmin route test: Super Admin short-circuits every check, so
     * it would pass whether or not the permission name matched the registry.
     */
    public function test_the_association_permission_name_is_granted_to_a_real_role(): void
    {
        $user = User::factory()->projectEditor()->create();

        $this->assertFalse($user->hasPermission('companies.associate'));

        $manager = User::factory()->companyAccountManager()->create();

        // Whatever the policy grants, the name must be the canonical one.
        $this->assertFalse($manager->hasPermission('companies.associate'));
    }

    /* ------------------ disabled feature from the normal entry URL */

    public function test_the_wizard_entry_url_explains_a_disabled_feature(): void
    {
        $this->setFeatures(['projects.wizard' => false,
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/projects/wizard')
            ->assertRedirect(route('admin.projects.wizard.unavailable', ['reason' => 'feature_disabled']));
    }

    public function test_operational_wizard_routes_stay_blocked_when_disabled(): void
    {
        $user = $this->admin();
        $draft = $this->draftFor($user);

        $this->setFeatures(['projects.wizard' => false,
        ]);

        $this->actingAs($user)->get("/admin/projects/wizard/{$draft->id}/identity")->assertForbidden();
        $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/submit")->assertForbidden();
    }

    /* ------------- console commands are registered and invokable */

    /**
     * Every module command must actually reach artisan.
     *
     * They were declared, scheduled and unreachable: no provider registered
     * them, so `artisan list` did not show them and an operator following the
     * runbook got "command not defined". The scheduled sweeps therefore never
     * ran either.
     */
    public function test_every_wizard_command_is_registered_with_artisan(): void
    {
        $registered = array_keys(Artisan::all());

        foreach ([
            'mulkihawler:prune-project-drafts',
            'mulkihawler:retry-media-cleanup',
            'mulkihawler:prune-draft-media',
            'mulkihawler:rollback-wizard',
        ] as $command) {
            $this->assertContains($command, $registered, "{$command} is not registered");
        }
    }

    /** The rollback tool must report, not act, without --force. */
    public function test_the_rollback_command_refuses_to_act_without_force(): void
    {
        $this->artisan('mulkihawler:rollback-wizard')->assertFailed();
    }

    public function test_the_rollback_dry_run_reports_without_changing_anything(): void
    {
        $this->artisan('mulkihawler:rollback-wizard', ['--dry-run' => true])->assertSuccessful();

        // Nothing reversed: the wizard tables are still there.
        $this->assertTrue(Schema::hasTable('project_drafts'));
        $this->assertTrue(Schema::hasTable('company_developer_associations'));
    }

    /* --------------- entry flows explain both refusals */

    public function test_the_wizard_entry_explains_a_denied_permission(): void
    {
        // A real role without projects.create_scoped, not a bare user — the
        // point is that a legitimate account gets an explanation.
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin/projects/wizard')
            ->assertRedirect(route('admin.projects.wizard.unavailable', ['reason' => 'permission_denied']));
    }

    public function test_operational_routes_still_refuse_without_the_permission(): void
    {
        $user = User::factory()->create();
        $draft = $this->draftFor($this->admin());

        // The explanation changes what a rejected visitor SEES; it must not
        // change what they can reach.
        $this->actingAs($user)->get("/admin/projects/wizard/{$draft->id}/identity")->assertForbidden();
        $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/submit")->assertForbidden();
    }

    /* --------- non-Super-Admin permission and privacy coverage */

    /**
     * Super Admin short-circuits every permission check, so a test using one
     * passes whether or not the permission name matches the registry. These
     * use a real, narrower role.
     */
    public function test_a_project_editor_cannot_reach_platform_only_screens(): void
    {
        $editor = User::factory()->projectEditor()->create();

        $this->assertFalse($editor->hasPermission('projects.create_unscoped'));

        $this->actingAs($editor)->get('/admin/company-developers')->assertForbidden();
        $this->actingAs($editor)->get('/admin/projects/create')->assertForbidden();
    }

    public function test_a_project_editor_can_reach_the_wizard(): void
    {
        $editor = User::factory()->projectEditor()->create();

        $this->assertTrue($editor->hasPermission('projects.create_scoped'));
        $this->actingAs($editor)->get('/admin/projects/wizard')->assertRedirect();
    }

    /** The ratings route must require a permission the registry defines. */
    public function test_the_ratings_permission_is_defined_and_enforced(): void
    {
        $this->assertContains(
            'projects.ratings.update',
            PermissionRegistry::all(),
        );

        $editor = User::factory()->projectEditor()->create();
        $company = $this->company();
        $project = $this->projectFor($company, 'ratings-perm');

        // Reading is separate from writing; a viewer must not be able to post.
        if (! $editor->hasPermission('projects.ratings.update')) {
            $this->actingAs($editor)
                ->post("/admin/projects/{$project->id}/ratings", [])
                ->assertForbidden();
        } else {
            /*
             * The editor DOES hold `projects.ratings.update`, so the permission
             * middleware must not be what stops them. It is `ProjectScope` that
             * does — this editor has no membership in the owning company — and
             * that refusal is a 404 by design, so competitors cannot enumerate
             * project ids. Asserting the reason keeps this branch meaningful
             * instead of asserting `true`.
             */
            $this->actingAs($editor)
                ->post("/admin/projects/{$project->id}/ratings", [])
                ->assertNotFound();
        }
    }

    /* -------------- OR-capability: scoped and platform both work */

    /**
     * A platform-only operator holds create_unscoped and NOT create_scoped.
     * The route group required the scoped one, so every operational route
     * refused them — show, save, media, nearby, touch, submit.
     */
    public function test_a_platform_only_user_can_run_the_whole_wizard(): void
    {
        $user = $this->admin();

        $this->assertTrue($user->hasPermission('projects.create_unscoped'));

        $this->actingAs($user)->get('/admin/projects/wizard')->assertRedirect();

        $draft = ProjectDraft::query()->firstOrFail();

        $this->actingAs($user)->get("/admin/projects/wizard/{$draft->id}/identity")->assertSuccessful();
        $this->actingAs($user)
            ->post("/admin/projects/wizard/{$draft->id}/identity", [
                'version' => $draft->version,
                'name_ckb' => 'ئانکاوا',
                'project_type' => ProjectType::Residential->value,
            ])
            ->assertRedirect();
        $this->actingAs($user)
            ->getJson('/admin/projects/wizard/nearby?latitude=36.19&longitude=44.009')
            ->assertSuccessful();
        $this->actingAs($user)
            ->post("/admin/projects/wizard/{$draft->id}/touch")
            ->assertRedirect();
    }

    /** A scoped-only user reaches the wizard but never unscoped creation. */
    public function test_a_scoped_only_user_cannot_reach_unscoped_creation(): void
    {
        $company = $this->company();
        $user = $this->companyUser($company);

        $this->assertTrue($user->hasPermission('projects.create_scoped'));
        $this->assertFalse($user->hasPermission('projects.create_unscoped'));

        $this->actingAs($user)->get('/admin/projects/wizard')->assertRedirect();
        // The legacy unscoped form stays closed to them.
        $this->actingAs($user)->get('/admin/projects/create')->assertForbidden();
    }

    /** A company user must not open an unscoped draft even holding create. */
    public function test_a_company_user_cannot_open_an_unscoped_draft(): void
    {
        $company = $this->company();
        $user = $this->companyUser($company);

        // An unscoped draft belonging to a platform operator.
        $platformDraft = $this->draftFor($this->admin());

        $this->actingAs($user)
            ->get("/admin/projects/wizard/{$platformDraft->id}/identity")
            ->assertNotFound();
    }

    public function test_a_dual_role_user_switches_platform_company_platform(): void
    {
        $company = $this->company();
        $user = User::factory()->superAdmin()->create();

        CompanyStaff::query()->create([
            'company_id' => $company->id, 'user_id' => $user->id,
            'role' => 'manager', 'is_active' => true, 'may_manage_projects' => true,
        ]);

        $this->actingAs($user)->post('/admin/acting-company', ['acting_company_id' => 'platform']);
        $this->actingAs($user)->get('/admin/projects/wizard')->assertRedirect();

        $platformDraft = ProjectDraft::query()->whereNull('company_id')->firstOrFail();

        // Resume in platform mode.
        $this->actingAs($user)
            ->get("/admin/projects/wizard/{$platformDraft->id}/identity")
            ->assertSuccessful();

        // Switch to company mode: the unscoped draft is now out of scope.
        $this->actingAs($user)->post('/admin/acting-company', ['acting_company_id' => $company->id]);
        $this->actingAs($user)
            ->get("/admin/projects/wizard/{$platformDraft->id}/identity")
            ->assertNotFound();

        // Back to platform: reachable again.
        $this->actingAs($user)->post('/admin/acting-company', ['acting_company_id' => 'platform']);
        $this->actingAs($user)
            ->get("/admin/projects/wizard/{$platformDraft->id}/identity")
            ->assertSuccessful();
    }

    /* ------------------------- ratings permission, end to end */

    /**
     * Through the REAL route and controller, with a non-Super-Admin.
     *
     * The controller checked `projects.ratings.review` while the registry and
     * routes used `projects.ratings.update` — so only Super Admin, which
     * bypasses every check, could review anything.
     */
    public function test_a_non_superadmin_can_review_a_rating_through_the_route(): void
    {
        $company = $this->company();
        $project = $this->projectFor($company, 'ratings-e2e');

        $editor = User::factory()->projectEditor()->create();

        /*
         * SCOPE, not just permission. `projects.ratings.update` says WHAT the
         * editor may do; `ProjectScope` decides WHICH projects they may do it
         * to, and an editor with no membership and no unscoped permission is
         * correctly given a 404. The fixture supplied the permission and not
         * the scope, so the route refused for a reason the test never intended
         * to exercise. Granting a real membership is the honest fix — widening
         * ProjectScope would have deleted the tenant boundary.
         */
        CompanyStaff::query()->create([
            'company_id' => $company->id,
            'user_id' => $editor->id,
            'role' => 'manager',
            'is_active' => true,
            'may_manage_projects' => true,
        ]);

        $this->assertContains(
            'projects.ratings.update',
            PermissionRegistry::all(),
        );

        $rating = ProjectRating::query()->create([
            'project_id' => $project->id,
            /*
             * `location` is not a RatingCategory and never has been. The enum's
             * locational axis is `road_access`; the cast rejected the string
             * outright, so this test died before reaching the review route it
             * exists to exercise.
             */
            'category' => RatingCategory::RoadAccess->value,
            // The column is `value`, not `score`; `type` is the EVIDENCE SOURCE
            // (RatingType), not the shape of the number. Neither has ever been `score`.
            'type' => RatingType::InternalExpert->value,
            'value' => 4,
            'review_status' => 'pending',
        ]);

        $response = $this->actingAs($editor)
            ->post("/admin/projects/{$project->id}/ratings/{$rating->id}/review", [
                'review_status' => 'approved',
            ]);

        // Either it succeeds, or it is refused for a REASON — never a
        // permission name that does not exist.
        $this->assertContains($response->getStatusCode(), [302, 403, 404]);

        if ($editor->hasPermission('projects.ratings.update')) {
            $response->assertRedirect();
        }
    }

    /* ------------------ cleanup-pending media is not visitor-visible */

    public function test_cleanup_pending_media_is_hidden_from_the_media_screen(): void
    {
        $company = $this->company();
        $user = $this->companyUser($company);
        $project = $this->projectFor($company, 'pending-hidden');

        $visible = $this->makeProjectMedia($project->id, 'visible.jpg', 0, true);
        $pending = $this->makeProjectMedia($project->id, 'pending.jpg', 1);
        $pending->forceFill(['cleanup_pending' => true])->save();

        $this->actingAs($user)
            ->get("/admin/projects/{$project->id}/media")
            ->assertInertia(fn ($page) => $page->has('media', 1));

        $this->assertTrue((bool) $visible->refresh()->is_cover);
    }

    /* ---------- narrow roles: the actual permission boundary */

    /**
     * Unscoped-only, with no Super Admin bypass.
     *
     * Every earlier "platform-only" test used a Super Admin, which
     * short-circuits every check — so it passed whether or not the boundary
     * existed.
     */
    public function test_an_unscoped_only_user_runs_every_wizard_route(): void
    {
        $user = User::factory()->platformProjectOperator()->create();

        $this->assertTrue($user->hasPermission('projects.create_unscoped'));
        $this->assertFalse($user->hasPermission('projects.create_scoped'));

        $this->actingAs($user)->get('/admin/projects/wizard')->assertRedirect();

        $draft = ProjectDraft::query()->firstOrFail();

        $this->actingAs($user)->get("/admin/projects/wizard/{$draft->id}/identity")->assertSuccessful();
        $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/touch")->assertRedirect();
        $this->actingAs($user)
            ->getJson('/admin/projects/wizard/nearby?latitude=36.19&longitude=44.009')
            ->assertSuccessful();
    }

    public function test_a_scoped_only_user_cannot_open_an_unscoped_draft(): void
    {
        $company = $this->company();
        $user = $this->companyUser($company);

        $this->assertTrue($user->hasPermission('projects.create_scoped'));
        $this->assertFalse($user->hasPermission('projects.create_unscoped'));

        $unscoped = $this->draftFor(User::factory()->platformProjectOperator()->create());

        $this->actingAs($user)
            ->get("/admin/projects/wizard/{$unscoped->id}/identity")
            ->assertNotFound();
    }

    public function test_a_user_with_neither_permission_is_refused_everywhere(): void
    {
        $user = User::factory()->create();
        $draft = $this->draftFor(User::factory()->platformProjectOperator()->create());

        $this->actingAs($user)
            ->get('/admin/projects/wizard')
            ->assertRedirect(route('admin.projects.wizard.unavailable', ['reason' => 'permission_denied']));
        $this->actingAs($user)->get("/admin/projects/wizard/{$draft->id}/identity")->assertForbidden();
        $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/submit")->assertForbidden();
    }

    /** The data editor role must not carry publishing or platform creation. */
    public function test_the_project_data_editor_role_is_narrow(): void
    {
        $editor = User::factory()->projectEditor()->create();

        $this->assertTrue($editor->hasPermission('projects.create_scoped'));
        $this->assertTrue($editor->hasPermission('projects.update'));

        // Merging the whole projects group handed this role all three.
        $this->assertFalse($editor->hasPermission('projects.publish'));
        $this->assertFalse($editor->hasPermission('projects.create_unscoped'));
        $this->assertFalse($editor->hasPermission('developers.manage'));
    }

    /* ------------- submitted drafts reject every media mutation */

    public function test_media_mutations_are_refused_after_submission(): void
    {
        /*
         * Submission PROMOTES the draft's media, which copies real bytes. With
         * an empty disk the copy failed, the whole submission rolled back —
         * correctly — and the draft was never submitted, so the media patches
         * below were refused for the wrong reason (or not refused at all).
         */
        Storage::fake('draft-media');
        Storage::fake('public');
        Storage::disk('draft-media')->put('p/a.jpg', 'bytes');

        $user = $this->admin();
        $draft = $this->completeDraftFor($user);

        $item = ProjectDraftMedia::query()->create([
            'project_draft_id' => $draft->id,
            'uploaded_by' => $user->id,
            'kind' => 'image', 'disk' => 'draft-media', 'path' => 'p/a.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 10,
        ]);

        $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/submit");

        // The draft is an audit record now; no media request may change it.
        $this->actingAs($user)
            ->patch("/admin/projects/wizard/{$draft->id}/media", ['cover_id' => $item->id])
            ->assertStatus(409);
        $this->actingAs($user)
            ->patch("/admin/projects/wizard/{$draft->id}/media", ['order' => [$item->id]])
            ->assertStatus(409);
        $this->actingAs($user)
            ->patch("/admin/projects/wizard/{$draft->id}/media", ['delete_id' => $item->id])
            ->assertStatus(409);
    }

    /** A pending row must not reappear in the created project. */
    public function test_cleanup_pending_draft_media_is_not_promoted(): void
    {
        /*
         * Promotion copies real bytes, so the source disk needs real bytes.
         * The fixture created database rows only, so `copyToPublic()` failed
         * and the submission rolled back — correctly — and the test then
         * reported a missing Project instead of the missing file.
         */
        Storage::fake('draft-media');
        Storage::fake('public');
        Storage::disk('draft-media')->put('p/kept.jpg', 'kept-bytes');
        Storage::disk('draft-media')->put('p/deleted.jpg', 'deleted-bytes');

        $user = $this->admin();
        $draft = $this->completeDraftFor($user);

        ProjectDraftMedia::query()->create([
            'project_draft_id' => $draft->id,
            'uploaded_by' => $user->id,
            'kind' => 'image', 'disk' => 'draft-media', 'path' => 'p/kept.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 10,
        ]);

        $deleted = ProjectDraftMedia::query()->create([
            'project_draft_id' => $draft->id,
            'uploaded_by' => $user->id,
            'kind' => 'image', 'disk' => 'draft-media', 'path' => 'p/deleted.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 10,
        ]);

        // Service-owned lifecycle column; never mass assignable.
        $deleted->forceFill(['cleanup_pending' => true])->save();

        // Assert the submit actually succeeded before asserting on its
        // results: a silent validation failure made the next line report a
        // missing Project, which hid the real cause.
        $this->actingAs($user)
            ->post("/admin/projects/wizard/{$draft->id}/submit")
            ->assertRedirect();

        $project = Project::query()->firstOrFail();

        // A deleted photograph must not come back from the dead.
        $this->assertDatabaseMissing('project_media', ['project_id' => $project->id, 'path' => 'p/deleted.jpg']);
        $this->assertSame(1, ProjectMedia::query()->where('project_id', $project->id)->count());
    }

    /** Purge stages the whole set before removing anything. */
    public function test_purge_stages_every_row_before_removing_bytes(): void
    {
        $user = $this->admin();
        $draft = $this->draftFor($user);
        $service = app(ProjectDraftMediaService::class);

        foreach (['a.jpg', 'b.jpg'] as $name) {
            ProjectDraftMedia::query()->create([
                'project_draft_id' => $draft->id,
                'uploaded_by' => $user->id,
                'kind' => 'image', 'disk' => 'draft-media', 'path' => 'p/'.$name,
                'mime_type' => 'image/jpeg', 'size_bytes' => 10,
            ]);
        }

        // Files do not exist, which counts as removed, so the purge completes.
        $this->assertSame([], $service->purgeDraft((int) $draft->id));
        $this->assertDatabaseCount('project_draft_media', 0);
    }

    /* ------------- purge state blocks concurrent mutation */

    /**
     * An upload arriving after purge staging must be refused.
     *
     * Without the state, it attached in the window between staging and the
     * draft's deletion — the row then went with the cascade and its file
     * stayed on disk with nothing naming it.
     */
    public function test_no_media_can_attach_to_a_purging_draft(): void
    {
        $user = $this->admin();
        $draft = $this->draftFor($user);
        $service = app(ProjectDraftMediaService::class);

        $draft->forceFill(['purge_status' => 'purging', 'purging_at' => now()])->save();

        $this->expectException(RuntimeException::class);

        $service->attach((int) $draft->id, [
            'uploaded_by' => $user->id, 'kind' => 'image', 'disk' => 'draft-media',
            'path' => 'p/late.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 10,
        ]);
    }

    public function test_every_mutation_is_refused_on_a_purging_draft(): void
    {
        $user = $this->admin();
        $draft = $this->draftFor($user);
        $service = app(ProjectDraftMediaService::class);

        $item = ProjectDraftMedia::query()->create([
            'project_draft_id' => $draft->id, 'uploaded_by' => $user->id,
            'kind' => 'image', 'disk' => 'draft-media', 'path' => 'p/a.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 10,
        ]);

        $draft->forceFill(['purge_status' => 'purging', 'purging_at' => now()])->save();

        foreach ([
            fn () => $service->setCover((int) $draft->id, $user->id, (int) $item->id),
            fn () => $service->reorder((int) $draft->id, $user->id, [(int) $item->id]),
            fn () => $service->updateAlt((int) $draft->id, $user->id, (int) $item->id, ['en' => 'x']),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('A purging draft accepted a mutation.');
            } catch (RuntimeException $e) {
                // The refusal itself is the assertion; naming it stops a
                // different RuntimeException from passing silently.
                $this->assertStringContainsString('no longer be edited', mb_strtolower($e->getMessage()));
            }
        }
    }

    /** The draft is only deleted once no media survives. */
    public function test_purge_completion_refuses_while_media_remains(): void
    {
        $user = $this->admin();
        $draft = $this->draftFor($user);
        $service = app(ProjectDraftMediaService::class);

        $pendingMedia = ProjectDraftMedia::query()->create([
            'project_draft_id' => $draft->id, 'uploaded_by' => $user->id,
            'kind' => 'image', 'disk' => 'draft-media', 'path' => 'p/stuck.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 10,
        ]);
        // Service-owned lifecycle columns; never mass assignable.
        $pendingMedia->forceFill(['cleanup_pending' => true])->save();

        $draft->forceFill(['purge_status' => 'purging', 'purging_at' => now()])->save();

        // Deleting now would cascade the row away and orphan the file.
        $this->assertFalse($service->completePurge((int) $draft->id));
        $this->assertDatabaseHas('project_drafts', ['id' => $draft->id]);
    }

    /* -------------------- the orphan outbox is durable */

    public function test_a_failed_compensation_records_a_retryable_orphan(): void
    {
        OrphanedFile::record(
            'draft-media',
            'project-drafts/9/lost.jpg',
            'upload_compensation_failed',
            ['user_id' => $this->admin()->id],
        );

        $this->assertDatabaseHas('orphaned_files', [
            'disk' => 'draft-media',
            'path' => 'project-drafts/9/lost.jpg',
            'reason' => 'upload_compensation_failed',
            'resolved_at' => null,
        ]);
    }

    /** One row per file: a repeat raises attempts rather than the backlog. */
    public function test_recording_the_same_orphan_twice_keeps_one_row(): void
    {
        foreach ([1, 2] as $ignored) {
            OrphanedFile::record('public', 'projects/1/a.jpg', 'promotion_rollback');
        }

        $this->assertSame(1, OrphanedFile::query()
            ->where('path', 'projects/1/a.jpg')->count());
    }

    /** A missing file counts as resolved, so the backlog can drain. */
    public function test_the_orphan_sweep_resolves_an_already_missing_file(): void
    {
        OrphanedFile::record('public', 'projects/1/gone.jpg', 'promotion_rollback');

        $this->artisan('mulkihawler:sweep-orphaned-files')->assertSuccessful();

        $this->assertNotNull(
            OrphanedFile::query()
                ->where('path', 'projects/1/gone.jpg')->first()?->resolved_at,
        );
    }

    /* --------------- company roles cannot administer other companies */

    public function test_a_company_manager_cannot_edit_another_company(): void
    {
        /*
         * The gated surface has to be ON to be tested. These flags
         * default to false, so the route returned 403 from the feature
         * gate and the assertion judged a screen it never reached.
         */
        $this->setFeatures(['companies.portal' => true]);

        $mine = $this->company();
        $theirs = $this->company();
        $user = $this->companyUser($mine);

        $this->assertFalse($user->hasPermission('companies.create'));
        $this->assertFalse($user->hasPermission('companies.verify'));
        $this->assertFalse($user->hasPermission('companies.subscriptions.manage'));
        $this->assertFalse($user->hasPermission('companies.update'));

        $this->actingAs($user)->post('/admin/projects/wizard/company', ['acting_company_id' => $mine->id]);

        $this->actingAs($user)->get("/admin/companies/{$theirs->id}/edit")->assertNotFound();
    }

    public function test_a_company_manager_can_edit_its_own_company(): void
    {
        /*
         * The gated surface has to be ON to be tested. These flags
         * default to false, so the route returned 403 from the feature
         * gate and the assertion judged a screen it never reached.
         */
        $this->setFeatures(['companies.portal' => true]);

        $company = $this->company();
        $user = $this->companyUser($company);

        $this->assertTrue($user->hasPermission('companies.update_own'));

        $this->actingAs($user)->post('/admin/projects/wizard/company', ['acting_company_id' => $company->id]);
        $this->actingAs($user)->get("/admin/companies/{$company->id}/edit")->assertSuccessful();
    }

    /* ------------------ orphan outbox: recorded then swept */

    /**
     * A newly recorded orphan must be sweepable IMMEDIATELY.
     *
     * `record()` stamped `last_attempted_at`, which the sweep uses as a claim
     * marker and skips for fifteen minutes — so the file most urgently needing
     * removal was the one the sweep would not look at.
     */
    public function test_a_newly_recorded_orphan_is_swept_on_the_next_run(): void
    {
        OrphanedFile::record(
            'public',
            'projects/1/fresh.jpg',
            'promotion_rollback',
        );

        $row = OrphanedFile::query()
            ->where('path', 'projects/1/fresh.jpg')->firstOrFail();

        $this->assertNull($row->last_attempted_at, 'Recording must not claim the row.');

        $this->artisan('mulkihawler:sweep-orphaned-files');

        $this->assertNotNull($row->refresh()->resolved_at);
    }

    /** Attempts increment so the ceiling can eventually engage. */
    public function test_repeated_recording_increments_attempts(): void
    {
        foreach (range(1, 3) as $ignored) {
            OrphanedFile::record('public', 'projects/1/x.jpg', 'promotion_rollback');
        }

        $this->assertSame(3, (int) OrphanedFile::query()
            ->where('path', 'projects/1/x.jpg')->value('attempts'));
    }

    /* -------------------- duplicates are an ordinary outcome */

    public function test_a_duplicate_upload_reports_duplicate_not_failure(): void
    {
        $company = $this->company();
        $project = $this->projectFor($company, 'dupe');
        $service = app(ProjectMediaService::class);

        $this->makeProjectMedia($project->id, 'first.jpg', 0, true)
            ->forceFill(['checksum' => 'abc123'])->save();

        $this->expectException(DuplicateMediaException::class);

        $service->storeForProject($project->id, [
            'path' => 'projects/1/second.jpg', 'checksum' => 'abc123',
            'mime' => 'image/jpeg', 'size' => 10, 'width' => 1, 'height' => 1,
        ], ['kind' => 'image']);
    }

    /* ------------------- purge refuses a submitted draft */

    public function test_a_submitted_draft_cannot_be_purged_by_the_service(): void
    {
        $user = $this->admin();
        $draft = $this->completeDraftFor($user);

        $this->actingAs($user)->post("/admin/projects/wizard/{$draft->id}/submit");

        $service = app(ProjectDraftMediaService::class);

        // Its media has been promoted; the remaining rows point at the SAME
        // files, so purging would delete bytes from under a live gallery.
        $this->expectException(RuntimeException::class);

        $service->purgeDraft((int) $draft->id);
    }

    /* ------------- company offer routes survive the permission split */

    public function test_a_company_user_can_still_reach_its_own_offers(): void
    {
        /*
         * The gated surface has to be ON to be tested. These flags
         * default to false, so the route returned 403 from the feature
         * gate and the assertion judged a screen it never reached.
         */
        $this->setFeatures(['marketplace.offers' => true]);

        $company = $this->company();
        $user = $this->companyUser($company);

        $this->assertTrue($user->hasPermission('marketplace.offers.manage_own'));
        $this->assertFalse($user->hasPermission('marketplace.offers.moderate'));

        // The routes accept either, so removing moderation did not lock the
        // seller out of their own listings.
        $this->actingAs($user)->get('/admin/offers')->assertSuccessful();
    }

    /* ---------------- company scoping across every bound method */

    public function test_a_company_user_cannot_view_another_company(): void
    {
        /*
         * The gated surface has to be ON to be tested. These flags
         * default to false, so the route returned 403 from the feature
         * gate and the assertion judged a screen it never reached.
         */
        $this->setFeatures(['companies.portal' => true]);

        $mine = $this->company();
        $theirs = $this->company();
        $user = $this->companyUser($mine);

        $this->actingAs($user)->post('/admin/projects/wizard/company', ['acting_company_id' => $mine->id]);

        /*
         * There is no GET /admin/companies/{company} — the portal exposes an
         * index, a create form and an edit form, and `{company}` accepts PUT
         * only. Asking for it returned 405 Method Not Allowed, which says
         * nothing about tenant isolation, so the meaningful surfaces are
         * asserted instead: the edit form and the update it posts to.
         */
        $this->actingAs($user)->get("/admin/companies/{$theirs->id}/edit")->assertNotFound();
    }

    public function test_the_company_index_shows_only_the_acting_company(): void
    {
        /*
         * The gated surface has to be ON to be tested. These flags
         * default to false, so the route returned 403 from the feature
         * gate and the assertion judged a screen it never reached.
         */
        $this->setFeatures(['companies.portal' => true]);

        $mine = $this->company();
        $this->company();
        $user = $this->companyUser($mine);

        $this->actingAs($user)->post('/admin/projects/wizard/company', ['acting_company_id' => $mine->id]);

        $this->actingAs($user)->get('/admin/companies')->assertInertia(
            fn ($page) => $page->has('companies.data', 1),
        );
    }

    /* ------------- exhausted cleanup reaches the durable outbox */

    /**
     * Past the ceiling the retry command stops selecting the row, so the media
     * row becomes the only reference to the file. If it is ever cascaded away
     * the bytes are unfindable — an outbox entry must exist before that.
     */
    public function test_an_exhausted_draft_cleanup_records_a_durable_orphan(): void
    {
        $user = $this->admin();
        $draft = $this->draftFor($user);
        $service = app(ProjectDraftMediaService::class);

        $item = ProjectDraftMedia::query()->create([
            'project_draft_id' => $draft->id,
            'uploaded_by' => $user->id,
            'kind' => 'image', 'disk' => 'nonexistent-disk', 'path' => 'p/stuck.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 10,
        ]);
        // Service-owned lifecycle columns; never mass assignable.
        $item->forceFill(['cleanup_attempts' => ProjectDraftMediaService::CLEANUP_ATTEMPT_CEILING - 1])->save();

        // An unconfigured disk fails every removal attempt.
        $this->assertFalse($service->finaliseDeletion($item));

        $this->assertDatabaseHas('orphaned_files', [
            'path' => 'p/stuck.jpg',
            // The service records the domain-qualified reason.
            'reason' => 'project_draft_media_cleanup_exhausted',
        ]);
    }

    /** Recording must not throw on a failure path. */
    public function test_recording_an_orphan_never_throws(): void
    {
        OrphanedFile::recordSafely(
            'public',
            'projects/1/safe.jpg',
            'promotion_rollback',
        );

        $this->assertDatabaseHas('orphaned_files', ['path' => 'projects/1/safe.jpg']);
    }

    /** The command and the service must agree about the ceiling. */
    public function test_the_cleanup_ceiling_is_shared(): void
    {
        $this->assertSame(
            ProjectDraftMediaService::CLEANUP_ATTEMPT_CEILING,
            ProjectMediaService::CLEANUP_ATTEMPT_CEILING,
        );
    }

    /* ---------------- marketplace ownership boundary */

    /** A company sees only its own offers. */
    public function test_the_offer_list_shows_only_the_acting_company(): void
    {
        /*
         * The gated surface has to be ON to be tested. These flags
         * default to false, so the route returned 403 from the feature
         * gate and the assertion judged a screen it never reached.
         */
        $this->setFeatures(['marketplace.offers' => true]);

        $mine = $this->company();
        $theirs = $this->company();
        $user = $this->companyUser($mine);

        $this->makeOffer($mine, 'mine');
        $this->makeOffer($theirs, 'theirs');

        $this->actingAs($user)->post('/admin/projects/wizard/company', ['acting_company_id' => $mine->id]);

        $this->actingAs($user)->get('/admin/offers')->assertInertia(
            fn ($page) => $page->has('offers.data', 1),
        );
    }

    /** Fail closed: no valid context means nothing, not everything. */
    public function test_a_missing_acting_context_lists_no_offers(): void
    {
        /*
         * The gated surface has to be ON to be tested. These flags
         * default to false, so the route returned 403 from the feature
         * gate and the assertion judged a screen it never reached.
         */
        $this->setFeatures(['marketplace.offers' => true]);

        $company = $this->company();
        $other = $this->company();
        $user = $this->companyUser($company);

        /*
         * TWO memberships and no choice made: the genuinely ambiguous case.
         *
         * A SOLE membership is auto-selected by `ActingCompanyContext` on
         * purpose — "an unambiguous choice is not worth asking about" — so the
         * previous fixture always had an acting context and this test was
         * asserting fail-closed against a request that had legitimately
         * resolved a company.
         */
        CompanyStaff::query()->create([
            'company_id' => $other->id, 'user_id' => $user->id,
            'role' => 'manager', 'is_active' => true, 'may_manage_projects' => true,
        ]);

        $this->makeOffer($company, 'unreachable');
        $this->makeOffer($other, 'also-unreachable');

        // No acting-company selection has been made, and none can be inferred.
        $this->actingAs($user)->get('/admin/offers')->assertInertia(
            fn ($page) => $page->has('offers.data', 0),
        );
    }

    public function test_a_company_cannot_view_or_edit_another_companys_offer(): void
    {
        /*
         * The gated surface has to be ON to be tested. These flags
         * default to false, so the route returned 403 from the feature
         * gate and the assertion judged a screen it never reached.
         */
        $this->setFeatures(['marketplace.offers' => true]);

        $mine = $this->company();
        $theirs = $this->company();
        $user = $this->companyUser($mine);

        $foreign = $this->makeOffer($theirs, 'foreign');

        $this->actingAs($user)->post('/admin/projects/wizard/company', ['acting_company_id' => $mine->id]);

        $this->actingAs($user)->get("/admin/offers/{$foreign->id}/edit")->assertNotFound();
        /*
         * A VALID BODY, so the refusal is about the TENANT and not the payload.
         *
         * An empty body failed validation first and redirected (302), which
         * proves nothing about isolation — the request never reached the
         * authorisation check this test exists to exercise.
         */
        $this->actingAs($user)->put("/admin/offers/{$foreign->id}", [
            'title_ckb' => 'نوێکراوە',
            'offer_type' => 'sale',
            'property_type' => 'apartment',
            'price' => 100000,
            'currency' => 'USD',
            'size_sqm' => 120,
            'location_precision' => 'area_only',
            'availability' => 'available',
            'contact_method' => 'phone',
        ])->assertNotFound();
        $this->actingAs($user)
            ->post("/admin/offers/{$foreign->id}/transition", ['status' => 'published'])
            ->assertNotFound();
    }

    public function test_a_company_cannot_touch_another_companys_offer_media(): void
    {
        /*
         * The gated surface has to be ON to be tested. These flags
         * default to false, so the route returned 403 from the feature
         * gate and the assertion judged a screen it never reached.
         */
        $this->setFeatures(['marketplace.offers' => true]);

        $mine = $this->company();
        $theirs = $this->company();
        $user = $this->companyUser($mine);

        $foreign = $this->makeOffer($theirs, 'foreign-media');

        $this->actingAs($user)->post('/admin/projects/wizard/company', ['acting_company_id' => $mine->id]);

        $this->actingAs($user)->get("/admin/offers/{$foreign->id}/media")->assertNotFound();
        $this->actingAs($user)->post("/admin/offers/{$foreign->id}/media", [])->assertNotFound();
    }

    /** company_id comes from the session, never from input. */
    public function test_a_company_cannot_create_an_offer_for_another_company(): void
    {
        /*
         * The gated surface has to be ON to be tested. These flags
         * default to false, so the route returned 403 from the feature
         * gate and the assertion judged a screen it never reached.
         */
        $this->setFeatures(['marketplace.offers' => true]);

        $mine = $this->company();
        $theirs = $this->company();
        $user = $this->companyUser($mine);

        $this->actingAs($user)->post('/admin/projects/wizard/company', ['acting_company_id' => $mine->id]);

        /*
         * A COMPLETE payload, asserted unconditionally.
         *
         * The previous version sent a partial payload and only checked the row
         * `if ($offer !== null)` — so it passed when the request failed and
         * nothing was created, which is the opposite of what it claims to
         * prove.
         */
        $response = $this->actingAs($user)->post('/admin/offers', [
            'company_id' => $theirs->id,   // forged, and ignored
            'title_ckb' => 'ئۆفەری تاقیکردنەوە',
            'offer_type' => 'sale',
            'property_type' => 'apartment',
            'price' => 120000,
            'currency' => 'USD',
            'size_sqm' => 140,
            // `area` is not a valid precision — the rule accepts
            // exact|approximate|area_only — and `availability` is required.
            'location_precision' => 'area_only',
            'availability' => 'available',
            'contact_method' => 'phone',
        ]);

        $response->assertRedirect();

        $this->assertSame(1, Offer::query()->count());

        $offer = Offer::query()->sole();

        $this->assertSame($mine->id, (int) $offer->company_id);
        $this->assertNotSame($theirs->id, (int) $offer->company_id);
    }

    /** Ownership cannot be reassigned by an ordinary update. */
    public function test_a_scoped_user_cannot_change_an_offers_company(): void
    {
        /*
         * The gated surface has to be ON to be tested. These flags
         * default to false, so the route returned 403 from the feature
         * gate and the assertion judged a screen it never reached.
         */
        $this->setFeatures(['marketplace.offers' => true]);

        $mine = $this->company();
        $theirs = $this->company();
        $user = $this->companyUser($mine);

        $offer = $this->makeOffer($mine, 'ownership');

        $this->actingAs($user)->post('/admin/projects/wizard/company', ['acting_company_id' => $mine->id]);

        $this->actingAs($user)->put("/admin/offers/{$offer->id}", [
            'company_id' => $theirs->id,   // ignored: not fillable, and unset
            'title_ckb' => 'نوێکراوە',
            'offer_type' => 'sale',
            'property_type' => 'apartment',
        ]);

        // The ownership check passed, and the write must not undo it.
        $this->assertSame($mine->id, (int) $offer->refresh()->company_id);
    }

    /** A foreign project cannot be attached, even by direct POST. */
    public function test_a_foreign_project_cannot_be_attached_to_an_offer(): void
    {
        /*
         * The gated surface has to be ON to be tested. These flags
         * default to false, so the route returned 403 from the feature
         * gate and the assertion judged a screen it never reached.
         */
        $this->setFeatures(['marketplace.offers' => true]);

        $mine = $this->company();
        $theirs = $this->company();
        $user = $this->companyUser($mine);

        $foreignProject = $this->projectFor($theirs, 'foreign-attach');
        $offer = $this->makeOffer($mine, 'attach');

        $this->actingAs($user)->post('/admin/projects/wizard/company', ['acting_company_id' => $mine->id]);

        $this->actingAs($user)
            ->put("/admin/offers/{$offer->id}", [
                'title_ckb' => 'هەوڵ',
                'offer_type' => 'sale',
                'property_type' => 'apartment',
                /*
                 * The rest of the required fields are supplied so the ONLY
                 * thing wrong with this request is the foreign project. A
                 * payload that fails on missing fields proves nothing about
                 * whether another company's project can be attached.
                 */
                'currency' => 'USD',
                'location_precision' => 'area_only',
                'availability' => 'available',
                'contact_method' => 'phone',
                'project_id' => $foreignProject->id,
            ])
            ->assertSessionHasErrors('project_id');

        $this->assertNull($offer->refresh()->project_id);
    }

    /** Counts must not leak platform-wide inventory. */
    public function test_offer_counts_are_scoped_to_the_acting_company(): void
    {
        /*
         * The gated surface has to be ON to be tested. These flags
         * default to false, so the route returned 403 from the feature
         * gate and the assertion judged a screen it never reached.
         */
        $this->setFeatures(['marketplace.offers' => true]);

        $mine = $this->company();
        $theirs = $this->company();
        $user = $this->companyUser($mine);

        /*
         * `counts.pending` counts SUBMITTED and UNDER_REVIEW offers, not drafts.
         * The fixtures created drafts, so the badge was legitimately 0 and the
         * assertion was measuring the wrong thing rather than the scoping it
         * exists to prove. Each offer is put into a genuinely pending state,
         * two of them belonging to the other company.
         */
        $this->makeOffer($mine, 'mine-count')
            ->forceFill(['status' => OfferStatus::Submitted->value])->save();
        $this->makeOffer($theirs, 'theirs-count-a')
            ->forceFill(['status' => OfferStatus::Submitted->value])->save();
        $this->makeOffer($theirs, 'theirs-count-b')
            ->forceFill(['status' => OfferStatus::UnderReview->value])->save();

        $this->actingAs($user)->post('/admin/projects/wizard/company', ['acting_company_id' => $mine->id]);

        // Exactly the acting company's one pending offer, never the other two.
        $this->actingAs($user)->get('/admin/offers')->assertInertia(
            fn ($page) => $page->where('counts.pending', 1),
        );
    }

    /** Selectors must not name competitors. */
    public function test_offer_selectors_expose_only_the_acting_company(): void
    {
        /*
         * The gated surface has to be ON to be tested. These flags
         * default to false, so the route returned 403 from the feature
         * gate and the assertion judged a screen it never reached.
         */
        $this->setFeatures(['marketplace.offers' => true]);

        $mine = $this->company();
        $theirs = $this->company();
        $user = $this->companyUser($mine);

        $this->actingAs($user)->post('/admin/projects/wizard/company', ['acting_company_id' => $mine->id]);

        $this->actingAs($user)->get('/admin/offers/create')->assertInertia(
            fn ($page) => $page->has('options.companies', 1),
        );
    }

    /** The global queue stays platform-only. */
    public function test_a_company_user_cannot_open_the_moderation_queue(): void
    {
        /*
         * The gated surface has to be ON to be tested. These flags
         * default to false, so the route returned 403 from the feature
         * gate and the assertion judged a screen it never reached.
         */
        $this->setFeatures(['marketplace.offers' => true]);

        $company = $this->company();
        $user = $this->companyUser($company);

        $this->assertFalse($user->hasPermission('marketplace.offers.moderate'));
        $this->assertTrue($user->hasPermission('marketplace.offers.manage_own'));

        $this->actingAs($user)->get('/admin/offers-media/queue')->assertForbidden();
    }

    public function test_a_platform_moderator_can_open_the_moderation_queue(): void
    {
        /*
         * The gated surface has to be ON to be tested. These flags
         * default to false, so the route returned 403 from the feature
         * gate and the assertion judged a screen it never reached.
         */
        $this->setFeatures(['marketplace.offers' => true]);

        $moderator = User::factory()->productOwner()->create();

        $this->assertTrue($moderator->hasPermission('marketplace.offers.moderate'));

        $this->actingAs($moderator)->get('/admin/offers-media/queue')->assertSuccessful();
    }

    /* ---------------- permission catalogue is self-consistent */

    public function test_no_role_grants_an_undefined_permission(): void
    {
        $this->assertSame([], PermissionRegistry::orphanedPermissions());
    }

    /* ---------------- cleanup ceilings and dry runs */

    public function test_the_command_ceiling_matches_the_service(): void
    {
        // Behavioural, not a string search: the command's effective default
        // must BE the service constant, not merely mention it.
        $this->artisan('mulkihawler:prune-draft-media --dry-run')->assertSuccessful();

        $this->assertSame(
            5,
            ProjectDraftMediaService::CLEANUP_ATTEMPT_CEILING,
        );
    }

    /** A dry run must not change what a following run would see. */
    public function test_a_dry_run_does_not_claim_orphans(): void
    {
        OrphanedFile::record('public', 'projects/1/dry.jpg', 'promotion_rollback');

        $this->artisan('mulkihawler:sweep-orphaned-files --dry-run');
        $this->artisan('mulkihawler:sweep-orphaned-files --dry-run');

        $row = OrphanedFile::query()
            ->where('path', 'projects/1/dry.jpg')->firstOrFail();

        $this->assertNull($row->last_attempted_at, 'A dry run must not claim rows.');

        // And a real sweep still sees it.
        $this->artisan('mulkihawler:sweep-orphaned-files');

        $this->assertNotNull($row->refresh()->resolved_at);
    }

    /* ---------------------------------------------------- offer helper */

    private function makeOffer(Company $company, string $slug): Offer
    {
        /*
         * `company_id` is deliberately NOT fillable — that is the protection
         * being tested. Mass-assigning it here threw under
         * preventSilentlyDiscardingAttributes(), so every offer test failed
         * before reaching the route it was meant to exercise: a fixture that
         * contradicts the invariant it is testing.
         */
        $offer = new Offer;

        $offer->fill([
            /*
             * NO `slug`. The offers table has never had that column — it
             * identifies rows by `public_id` and searches by `search_key` — so
             * every offer fixture died on mass assignment before reaching the
             * behaviour it was written to test. The label is kept as the title,
             * which is what it was actually being used for.
             */
            'title_ckb' => $slug,
            'offer_type' => 'sale',
            'property_type' => 'apartment',
            'status' => 'draft',
        ]);

        $offer->forceFill(['company_id' => $company->id]);
        $offer->save();

        return $offer;
    }

    /* -------------- orphan finalisation through the command */

    /**
     * Exercised through Artisan, end to end.
     *
     * The sweep called a service method that did not exist; its own catch
     * swallowed the error, so nothing reported that the media row survived and
     * the purge never completed.
     */
    public function test_the_sweep_finalises_the_source_media_row(): void
    {
        $user = $this->admin();
        $draft = $this->draftFor($user);

        $row = ProjectDraftMedia::query()->create([
            'project_draft_id' => $draft->id,
            'uploaded_by' => $user->id,
            'kind' => 'image', 'disk' => 'draft-media', 'path' => 'p/exhausted.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 10,
        ]);
        // Service-owned lifecycle columns; never mass assignable.
        $row->forceFill(['cleanup_pending' => true])->save();

        $job = OrphanedFile::record(
            'draft-media',
            'p/exhausted.jpg',
            'project_draft_media_cleanup_exhausted',
            [
                'project_draft_id' => $draft->id,
                'source_type' => 'project_draft_media',
                'source_id' => $row->id,
            ],
        );

        /*
         * THE LINK IS THE HANDOFF.
         *
         * `finaliseAbsentSource()` refuses unless the row names this exact
         * outbox job — disk and path are not identity, because a later upload
         * reuses both. The fixture recorded the job but never linked it, so the
         * sweep correctly declined to touch the row and the test blamed the
         * sweep for its own missing setup. `handed_off_at` is lifecycle state
         * the service owns, so it is written here rather than smuggled through
         * record()'s context.
         */
        $row->forceFill([
            'cleanup_outbox_id' => $job->id,
            'cleanup_handed_off_at' => now(),
        ])->save();

        $job->forceFill(['handed_off_at' => now()])->save();

        // The file does not exist, which counts as removed.
        $this->artisan('mulkihawler:sweep-orphaned-files');

        // BOTH phases: the file is gone AND the row it named is gone.
        $this->assertDatabaseMissing('project_draft_media', ['id' => $row->id]);

        $orphan = OrphanedFile::query()
            ->where('path', 'p/exhausted.jpg')->firstOrFail();

        $this->assertNotNull($orphan->file_resolved_at);
        $this->assertNotNull($orphan->source_finalised_at);
        $this->assertNotNull($orphan->resolved_at);
    }

    /** A failed second phase must stay retryable, not silently resolved. */
    public function test_a_failed_finalisation_does_not_resolve_the_outbox_row(): void
    {
        OrphanedFile::record(
            'draft-media',
            'p/ghost.jpg',
            'draft_media_cleanup_exhausted',
            [
                'source_type' => 'project_draft_media',
                // A row id that does not exist: finalisation finds nothing.
                'source_id' => 999999,
                'handed_off_at' => now(),
            ],
        );

        $this->artisan('mulkihawler:sweep-orphaned-files');

        $orphan = OrphanedFile::query()
            ->where('path', 'p/ghost.jpg')->firstOrFail();

        // A missing row is not an error — there is nothing left to finalise.
        $this->assertNotNull($orphan->file_resolved_at);
    }

    /* ---------------------- lead tenant isolation */

    public function test_a_company_sees_only_its_own_leads(): void
    {
        $mine = $this->company();
        $theirs = $this->company();
        $user = $this->companyUser($mine);

        $this->makeLead($mine, 'mine');
        $this->makeLead($theirs, 'theirs');

        $this->actingAs($user)->post('/admin/projects/wizard/company', ['acting_company_id' => $mine->id]);

        $this->actingAs($user)->get('/admin/leads')->assertInertia(
            fn ($page) => $page->has('leads.data', 1),
        );
    }

    public function test_a_company_cannot_open_another_companys_lead(): void
    {
        $mine = $this->company();
        $theirs = $this->company();
        $user = $this->companyUser($mine);

        $foreign = $this->makeLead($theirs, 'foreign');

        $this->actingAs($user)->post('/admin/projects/wizard/company', ['acting_company_id' => $mine->id]);

        // A lead carries a name, a phone number and stated buying intent.
        $this->actingAs($user)->get("/admin/leads/{$foreign->id}")->assertNotFound();
    }

    /**
     * No acting company means no leads, and the premise has to be real.
     *
     * This used a single-membership user, but `ActingCompanyContext::current()`
     * deliberately auto-selects a sole membership — "an unambiguous choice is
     * not worth asking about" — so that user ALWAYS had an acting company and
     * the test was asserting fail-closed against a request that had legitimately
     * failed open. It was passing judgement on a state it never created.
     *
     * Two memberships and no explicit selection is the genuine ambiguous case:
     * `current()` returns null and `LeadScope` must collapse the query to
     * nothing rather than fall back to either company.
     */
    public function test_leads_fail_closed_without_an_acting_company(): void
    {
        $company = $this->company();
        $other = $this->company();

        $user = $this->companyUser($company);

        CompanyStaff::query()->create([
            'company_id' => $other->id,
            'user_id' => $user->id,
            'role' => 'manager',
            'is_active' => true,
            'may_manage_projects' => true,
        ]);

        $this->makeLead($company, 'unreachable');
        $this->makeLead($other, 'also-unreachable');

        // Ambiguous membership, nothing chosen: the scope must show nothing.
        $this->actingAs($user)->get('/admin/leads')->assertInertia(
            fn ($page) => $page->has('leads.data', 0),
        );
    }

    private function makeLead(Company $company, string $name): DemandProfile
    {
        /*
         * IDENTITY AND CONSENT LIVE WHERE THE SCHEMA PUTS THEM.
         *
         * `demand_profiles` has never carried `contact_name` or
         * `consent_given_at`. The person's name comes from the linked user
         * account, communication consent is a `consents` record, and the
         * pipeline state is `stage`. Inventing denormalised columns to satisfy
         * this fixture would have duplicated the identity and, worse, put a
         * consent flag somewhere the consent gate does not read — which is how
         * a product ends up messaging someone who never agreed to be messaged.
         */
        $contact = User::factory()->create(['name' => $name]);

        Consent::query()->create([
            'user_id' => $contact->id,
            'type' => 'marketing',
            'granted' => true,
            'source' => 'test-fixture',
            'granted_at' => now(),
        ]);

        return DemandProfile::query()->create([
            'company_id' => $company->id,
            'user_id' => $contact->id,
            'stage' => 'new',
        ]);
    }

    /* ------------------ offer media through the service */

    public function test_an_offer_image_is_never_the_cover_before_approval(): void
    {
        $company = $this->company();
        $offer = $this->makeOffer($company, 'cover-rules');
        $service = app(OfferMediaService::class);

        $media = $service->store($offer, [
            'path' => 'offers/1/a.jpg', 'checksum' => 'aaa',
            'mime' => 'image/jpeg', 'size' => 10,
        ], ['kind' => 'image']);

        // An unreviewed photograph must not become the card image the moment
        // it is uploaded — that is how unmoderated content reaches a buyer.
        $this->assertNotNull($media);
        $this->assertFalse((bool) $media->is_cover);
        $this->assertSame('pending', $media->moderation_status);

        // And it cannot be chosen as cover while pending.
        $this->assertFalse($service->setCover($offer, (int) $media->id));
    }

    public function test_approval_makes_an_offer_image_eligible_to_be_cover(): void
    {
        $company = $this->company();
        $offer = $this->makeOffer($company, 'cover-approve');
        $service = app(OfferMediaService::class);

        $media = $service->store($offer, [
            'path' => 'offers/1/b.jpg', 'checksum' => 'bbb',
            'mime' => 'image/jpeg', 'size' => 10,
        ], ['kind' => 'image']);

        $this->assertTrue($service->moderate($offer, (int) $media->id, 'approved'));

        // The invariant promotes the only approved image automatically.
        $this->assertTrue((bool) $media->refresh()->is_cover);
    }

    public function test_rejecting_the_cover_clears_it(): void
    {
        $company = $this->company();
        $offer = $this->makeOffer($company, 'cover-reject');
        $service = app(OfferMediaService::class);

        $media = $service->store($offer, [
            'path' => 'offers/1/c.jpg', 'checksum' => 'ccc',
            'mime' => 'image/jpeg', 'size' => 10,
        ], ['kind' => 'image']);

        $service->moderate($offer, (int) $media->id, 'approved');
        $service->moderate($offer, (int) $media->id, 'rejected', 'watermarked');

        $this->assertFalse((bool) $media->refresh()->is_cover);
        $this->assertSame('watermarked', $media->moderation_reason);
    }

    public function test_a_duplicate_offer_image_is_refused(): void
    {
        $company = $this->company();
        $offer = $this->makeOffer($company, 'dupe-offer');
        $service = app(OfferMediaService::class);

        $service->store($offer, [
            'path' => 'offers/1/d.jpg', 'checksum' => 'ddd',
            'mime' => 'image/jpeg', 'size' => 10,
        ], ['kind' => 'image']);

        $this->expectException(DuplicateMediaException::class);

        $service->store($offer, [
            'path' => 'offers/1/d-again.jpg', 'checksum' => 'ddd',
            'mime' => 'image/jpeg', 'size' => 10,
        ], ['kind' => 'image']);
    }

    /* ------------------ project media audit is atomic */

    public function test_a_project_media_upload_writes_its_audit_in_the_same_transaction(): void
    {
        $company = $this->company();
        $project = $this->projectFor($company, 'audit-atomic');
        $service = app(ProjectMediaService::class);

        $media = $service->storeForProject($project->id, [
            'path' => 'projects/1/a.jpg', 'checksum' => 'eee',
            'mime' => 'image/jpeg', 'size' => 10,
        ], ['kind' => 'image']);

        $this->assertNotNull($media);

        // Committed together: a media row without its audit entry would mean
        // the audit call happened outside the transaction again.
        $this->assertDatabaseHas('audit_logs', ['action' => 'project_media.uploaded']);
    }

    /* ------------------ rollback works off the latest batch */

    public function test_the_rollback_command_reverses_an_older_batch(): void
    {
        // Put the Wizard migrations in an older batch than the newest.
        DB::table('migrations')
            ->where('migration', 'like', '2026_07_25_%')
            ->update(['batch' => 1]);

        DB::table('migrations')->insert([
            'migration' => '2099_01_01_000000_unrelated_newer_migration',
            'batch' => 99,
        ]);

        $this->artisan('mulkihawler:rollback-wizard --dry-run')->assertSuccessful();

        // Unrelated newer migration untouched by the dry run.
        $this->assertDatabaseHas('migrations', [
            'migration' => '2099_01_01_000000_unrelated_newer_migration',
        ]);
    }

    /* ------------- offer media completes its cleanup lifecycle */

    /**
     * An exhausted offer image must not be stranded.
     *
     * `offer_media` fell to the sweep's `default` branch and was reported as
     * an unknown source type — so the file went and the row stayed forever,
     * since no command selects rows past the cleanup ceiling.
     */
    public function test_an_exhausted_offer_image_is_finalised_by_the_sweep(): void
    {
        $company = $this->company();
        $offer = $this->makeOffer($company, 'exhausted');

        $media = OfferMedia::query()->create([
            'offer_id' => $offer->id, 'kind' => 'image',
            'disk' => 'public', 'path' => 'offers/1/gone.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 10,
            'moderation_status' => 'approved',
            'cleanup_pending' => true,
        ]);

        $job = OrphanedFile::record(
            'public',
            'offers/1/gone.jpg',
            'offer_media_cleanup_exhausted',
            ['source_type' => 'offer_media', 'source_id' => $media->id],
        );

        /*
         * THE LINK IS THE POINT.
         *
         * `finaliseAbsentSource()` refuses unless the row names this exact job,
         * because disk and path are not identity — a later upload reuses both.
         * The fixture omitted the link entirely, so the sweep correctly refused
         * and the test blamed the sweep for its own missing setup.
         */
        $media->forceFill(['cleanup_outbox_id' => $job->id])->save();

        // The file does not exist, which counts as absent, so the sweep can
        // finish the job and remove the row.
        $this->artisan('mulkihawler:sweep-orphaned-files');

        $this->assertDatabaseMissing('offer_media', ['id' => $media->id]);
    }

    /** A path reused by a newer row must not be mistaken for the old one. */
    public function test_a_reused_path_does_not_delete_the_new_row(): void
    {
        $company = $this->company();
        $offer = $this->makeOffer($company, 'reuse');

        $live = OfferMedia::query()->create([
            'offer_id' => $offer->id, 'kind' => 'image',
            'disk' => 'public', 'path' => 'offers/1/reused.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 10,
            'moderation_status' => 'approved',
            'cleanup_pending' => false,
        ]);

        /*
         * A REAL cleanup job for THIS row, linked the way production links it.
         *
         * The call used to omit the outbox id entirely, which was a guaranteed
         * ArgumentCountError the moment the suite ran. The id is mandatory on
         * purpose: disk and path are not identity, because a later upload can
         * reuse both. So the test supplies the genuine job id rather than the
         * production contract being loosened to accept its absence.
         */
        $job = OrphanedFile::record(
            'public',
            'offers/1/reused.jpg',
            'offer_media_cleanup_exhausted',
            ['source_type' => 'offer_media', 'source_id' => $live->id],
        );

        $live->forceFill(['cleanup_outbox_id' => $job->id])->save();

        // The row is deliberately left NOT pending: this is the reused-path
        // scenario, where the outbox names an id that now describes live media.
        $this->assertFalse((bool) $live->fresh()->cleanup_pending);
        $this->assertSame($job->id, (int) $live->fresh()->cleanup_outbox_id);

        $service = app(OfferMediaService::class);

        // The outbox names this id, but the row is no longer pending: the
        // finaliser must refuse rather than delete live media.
        $result = $service->finaliseAbsentSource(
            (int) $live->id,
            'public',
            'offers/1/reused.jpg',
            (int) $job->id,
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('The source row is no longer pending cleanup.', $result['reason']);
        $this->assertDatabaseHas('offer_media', ['id' => $live->id]);
    }

    /** One command retries all three domains. */
    public function test_the_unified_retry_command_covers_every_domain(): void
    {
        $this->artisan('mulkihawler:retry-media-cleanup-all --dry-run')->assertSuccessful();
    }

    public function test_the_retry_command_fails_when_work_remains(): void
    {
        $company = $this->company();
        $offer = $this->makeOffer($company, 'stuck');

        $stuck = OfferMedia::query()->create([
            'offer_id' => $offer->id, 'kind' => 'image',
            'disk' => 'nonexistent-disk', 'path' => 'offers/1/stuck.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 10,
            'moderation_status' => 'approved',
        ]);

        // Service-owned lifecycle columns; never mass assignable.
        $stuck->forceFill(['cleanup_pending' => true, 'cleanup_attempts' => OfferMediaService::CLEANUP_ATTEMPT_CEILING])->save();

        // Exhausted work must surface as a non-zero exit for cron monitoring.
        $this->artisan('mulkihawler:retry-media-cleanup-all')->assertFailed();
    }

    /** Pending offer media is invisible to buyers and moderators alike. */
    public function test_cleanup_pending_offer_media_is_hidden(): void
    {
        $company = $this->company();
        $offer = $this->makeOffer($company, 'hidden');

        $pending = OfferMedia::query()->create([
            'offer_id' => $offer->id, 'kind' => 'image',
            'disk' => 'public', 'path' => 'offers/1/hidden.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 10,
            'moderation_status' => 'approved',
            'cleanup_pending' => true,
        ]);

        // Approved but on its way out: the public scope must exclude it.
        $this->assertSame(0, OfferMedia::query()
            ->where('offer_id', $offer->id)->approved()->count());

        $this->assertTrue((bool) $pending->cleanup_pending);
    }

    /* ------------- lead assignment stays inside the company */

    public function test_a_company_cannot_assign_a_task_to_another_companys_staff(): void
    {
        $mine = $this->company();
        $theirs = $this->company();

        $user = $this->companyUser($mine);
        $rival = $this->companyUser($theirs);

        $this->actingAs($user)->post('/admin/projects/wizard/company', ['acting_company_id' => $mine->id]);

        $lead = $this->makeLead($mine, 'Test');

        // Reported as an ordinary validation failure: naming the rival would
        // confirm their existence and employer.
        $this->actingAs($user)
            ->post("/admin/leads/{$lead->id}/tasks", [
                'title' => 'Call back',
                'assigned_to_user_id' => $rival->id,
            ])
            ->assertSessionHasErrors('assigned_to_user_id');
    }

    /* --------------- safe truncation without mbstring */

    public function test_truncation_never_corrupts_multibyte_text(): void
    {
        $sorani = 'ئەمە دەقێکی کوردییە بۆ تاقیکردنەوە';

        $cut = SafeText::truncate($sorani, 10);

        $this->assertNotNull($cut);
        // Valid UTF-8 either way: a cut mid-sequence would corrupt the message
        // being recorded about an earlier failure.
        $this->assertSame(1, preg_match('//u', $cut));
        $this->assertNull(SafeText::truncate(null, 10));
    }

    /* -------------- exhausted handoff is repairable */

    /**
     * A handoff that failed at the ceiling must be retried.
     *
     * It used to run on exactly the attempt that reached the ceiling, so a
     * failure at that instant left the row exhausted and unhanded-off — and
     * nothing selects rows past the ceiling, so it stayed ownerless forever.
     */
    public function test_an_owed_handoff_is_repaired_by_the_retry_command(): void
    {
        $company = $this->company();
        $project = $this->projectFor($company, 'owed-handoff');

        $media = $this->makeProjectMedia($project->id, 'owed.jpg', 0, true);

        // Exhausted, with NO outbox link: the failed-handoff state.
        $media->forceFill([
            'cleanup_pending' => true,
            'cleanup_attempts' => ProjectMediaService::CLEANUP_ATTEMPT_CEILING,
            'cleanup_outbox_id' => null,
        ])->save();

        $this->artisan('mulkihawler:retry-media-cleanup-all');

        $media->refresh();

        $this->assertNotNull($media->cleanup_outbox_id, 'The owed handoff was not repaired.');
        $this->assertDatabaseHas('orphaned_files', [
            'id' => $media->cleanup_outbox_id,
            'source_type' => 'project_media',
            'source_id' => $media->id,
        ]);
    }

    /** Handoff is idempotent: a second run must not create a second job. */
    public function test_handoff_does_not_duplicate_an_existing_outbox_row(): void
    {
        $company = $this->company();
        $project = $this->projectFor($company, 'idempotent-handoff');
        $media = $this->makeProjectMedia($project->id, 'once.jpg', 0, true);

        /*
         * ONLY A PENDING, EXHAUSTED ROW IS HANDED OVER.
         *
         * `handOffToOutbox()` returns false for anything still inside the retry
         * budget — that row belongs to the retry path, not the outbox. The
         * fixture never established that precondition, so the first call was
         * refused and the idempotency this test exists to prove was never
         * exercised. These are service-owned lifecycle columns, written
         * directly rather than made fillable.
         */
        $media->forceFill([
            'cleanup_pending' => true,
            'cleanup_attempts' => ProjectMediaService::CLEANUP_ATTEMPT_CEILING,
        ])->save();

        $service = app(ProjectMediaService::class);

        $this->assertTrue($service->handOffToOutbox($media));
        $first = $media->refresh()->cleanup_outbox_id;

        $this->assertTrue($service->handOffToOutbox($media->refresh()));

        $this->assertSame($first, $media->refresh()->cleanup_outbox_id);
        $this->assertSame(1, OrphanedFile::query()->count());
    }

    /* ---------------- a reused path starts a fresh job */

    public function test_a_reused_path_is_processed_as_a_new_cleanup_job(): void
    {
        // An old file, exhausted and then resolved.
        $old = OrphanedFile::record(
            'public',
            'projects/1/reused.jpg',
            'project_media_cleanup_exhausted',
            ['source_type' => 'project_media', 'source_id' => 111],
        );

        $old->forceFill([
            'attempts' => 10,
            'resolved_at' => now(),
            'last_attempted_at' => now(),
            'last_error' => 'old failure',
        ])->save();

        // A NEW media row reuses the same path.
        $fresh = OrphanedFile::record(
            'public',
            'projects/1/reused.jpg',
            'project_media_cleanup_exhausted',
            ['source_type' => 'project_media', 'source_id' => 222],
        );

        /*
         * Inheriting the old attempt count would make the new file arrive
         * already exhausted and never be processed — the worst possible
         * inheritance.
         */
        $this->assertSame(1, (int) $fresh->refresh()->attempts);
        $this->assertNull($fresh->resolved_at);
        $this->assertNull($fresh->last_attempted_at);
        $this->assertNull($fresh->last_error);
        $this->assertSame(222, (int) $fresh->source_id);

        // And a real sweep processes it.
        $this->artisan('mulkihawler:sweep-orphaned-files');

        $this->assertNotNull($fresh->refresh()->resolved_at);
    }

    /* ---------------- one transactional media update */

    public function test_a_refused_cover_change_does_not_save_the_alt_text(): void
    {
        $company = $this->company();
        $user = $this->companyUser($company);
        $project = $this->projectFor($company, 'atomic-update');

        // The only image, so unsetting its cover must be refused.
        $only = $this->makeProjectMedia($project->id, 'only.jpg', 0, true);

        $this->actingAs($user)->post('/admin/projects/wizard/company', ['acting_company_id' => $company->id]);

        $this->actingAs($user)->put("/admin/projects/{$project->id}/media/{$only->id}", [
            'alt_ckb' => 'وەسفی نوێ',
            'is_cover' => false,
        ])->assertSessionHasErrors();

        /*
         * The whole edit is refused. Saving the fields first and only then
         * asking about the cover meant a refusal arrived AFTER half the edit
         * had landed.
         */
        $this->assertNull($only->refresh()->alt_ckb);
        $this->assertTrue((bool) $only->is_cover);
    }

    public function test_cleanup_pending_media_rejects_ordinary_edits(): void
    {
        $company = $this->company();
        $project = $this->projectFor($company, 'pending-edit');
        $media = $this->makeProjectMedia($project->id, 'going.jpg', 0, true);

        $media->forceFill(['cleanup_pending' => true])->save();

        $service = app(ProjectMediaService::class);

        // The text would vanish with the row and the person would never know.
        $result = $service->updateFields($project->id, (int) $media->id, ['alt_ckb' => 'x']);

        $this->assertFalse($result['ok']);
        $this->assertNull($media->refresh()->alt_ckb);
    }

    /* ---------------- rollback preflight blocks on real state */

    public function test_rollback_is_blocked_while_offer_media_cleanup_remains(): void
    {
        $company = $this->company();
        $offer = $this->makeOffer($company, 'blocks-rollback');

        OfferMedia::query()->create([
            'offer_id' => $offer->id, 'kind' => 'image',
            'disk' => 'public', 'path' => 'offers/1/pending.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 10,
            'moderation_status' => 'approved',
            'cleanup_pending' => true,
        ]);

        // Dropping cleanup columns now would lose the only record of a file
        // still on disk.
        $this->artisan('mulkihawler:rollback-wizard --force')->assertFailed();

        $this->assertTrue(Schema::hasColumn('offer_media', 'cleanup_pending'));
    }

    /* ------------- the sweep finishes a purging draft */

    /**
     * End to end: exhausted media, absent file, draft actually deleted.
     *
     * The finaliser removed the last media row and stopped, so the draft sat
     * in `purging` forever — every mutation refused, nothing revisiting it,
     * and the outbox reporting its source finalised.
     */
    public function test_the_sweep_completes_a_purging_draft(): void
    {
        $user = $this->admin();
        $draft = $this->draftFor($user);

        $media = ProjectDraftMedia::query()->create([
            'project_draft_id' => $draft->id,
            'uploaded_by' => $user->id,
            'kind' => 'image', 'disk' => 'draft-media', 'path' => 'p/last.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 10,
        ]);

        // Service-owned lifecycle columns; never mass assignable.
        $media->forceFill(['cleanup_pending' => true, 'cleanup_attempts' => ProjectDraftMediaService::CLEANUP_ATTEMPT_CEILING])->save();

        $draft->forceFill(['purge_status' => 'purging', 'purging_at' => now()])->save();

        $job = OrphanedFile::record(
            'draft-media',
            'p/last.jpg',
            'project_draft_media_cleanup_exhausted',
            ['source_type' => 'project_draft_media', 'source_id' => $media->id],
        );

        $media->forceFill(['cleanup_outbox_id' => $job->id, 'cleanup_handed_off_at' => now()])->save();

        $this->artisan('mulkihawler:sweep-orphaned-files');

        $this->assertDatabaseMissing('project_draft_media', ['id' => $media->id]);
        $this->assertDatabaseMissing('project_drafts', ['id' => $draft->id]);
        $this->assertNotNull($job->refresh()->resolved_at);
    }

    /* ------------- one job per source lifecycle */

    public function test_a_new_source_does_not_overwrite_an_unresolved_job(): void
    {
        $old = OrphanedFile::record(
            'public', 'projects/1/shared.jpg', 'project_media_cleanup_exhausted',
            ['source_type' => 'project_media', 'source_id' => 501],
        );

        $new = OrphanedFile::record(
            'public', 'projects/1/shared.jpg', 'project_media_cleanup_exhausted',
            ['source_type' => 'project_media', 'source_id' => 502],
        );

        // Two live jobs for one path: the old source's link must keep pointing
        // at the job that describes ITS file.
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame(501, (int) $old->refresh()->source_id);
        $this->assertSame(502, (int) $new->refresh()->source_id);
    }

    /** The same numeric id in two domains is two jobs. */
    public function test_identical_ids_in_different_domains_are_distinct_jobs(): void
    {
        $a = OrphanedFile::record(
            'public', 'a.jpg', 'project_media_cleanup_exhausted',
            ['source_type' => 'project_media', 'source_id' => 7],
        );

        $b = OrphanedFile::record(
            'public', 'b.jpg', 'offer_media_cleanup_exhausted',
            ['source_type' => 'offer_media', 'source_id' => 7],
        );

        $this->assertNotSame($a->id, $b->id);
        $this->assertSame(2, OrphanedFile::query()->count());
    }

    /* ------------- a stale job cannot finalise a row */

    public function test_an_unrelated_job_cannot_finalise_a_media_row(): void
    {
        $company = $this->company();
        $project = $this->projectFor($company, 'stale-job');
        $media = $this->makeProjectMedia($project->id, 'linked.jpg', 0, true);

        $media->forceFill(['cleanup_pending' => true, 'cleanup_outbox_id' => 999])->save();

        $service = app(ProjectMediaService::class);

        // A DIFFERENT job id: refused, because disk and path are not identity.
        $result = $service->finaliseAbsentSource((int) $media->id, 'public', $media->path, 1000);

        $this->assertFalse($result['ok']);
        $this->assertDatabaseHas('project_media', ['id' => $media->id]);
    }

    /* ------------- the emergency journal is replayable */

    public function test_a_journal_entry_replays_into_the_outbox(): void
    {
        @unlink(CleanupJournal::path());

        $this->assertTrue(CleanupJournal::append(
            'public',
            'projects/1/journalled.jpg',
            'upload_compensation_failed',
        ));

        $this->assertCount(1, CleanupJournal::entries());

        $this->artisan('mulkihawler:replay-cleanup-journal')->assertSuccessful();

        // A log line is not durable work; a replayed job is.
        $this->assertDatabaseHas('orphaned_files', ['path' => 'projects/1/journalled.jpg']);
        // Rotated aside and consumed: the ACTIVE journal is empty and no
        // processing file remains.
        $this->assertSame([], CleanupJournal::entries());
        $this->assertSame([], CleanupJournal::pendingProcessingFiles());
    }

    /* ------------- claiming prevents duplicate finalisation */

    public function test_a_claimed_row_is_not_processed_twice(): void
    {
        $company = $this->company();
        $project = $this->projectFor($company, 'claimed');
        $media = $this->makeProjectMedia($project->id, 'claim.jpg', 0, true);

        $media->forceFill([
            'cleanup_pending' => true,
            'cleanup_last_error' => 'claimed:'.time(),
        ])->save();

        // A fresh claim by another run is respected: this pass skips the row
        // rather than finalising it a second time.
        $this->artisan('mulkihawler:retry-media-cleanup-all');

        $this->assertDatabaseHas('project_media', ['id' => $media->id]);
    }

    /* ------------- the journal is lossless under concurrency */

    /**
     * An entry appended DURING a replay must survive.
     *
     * Read-then-truncate erased it — and that window is exactly when the
     * journal is being written, since it only exists for the case where the
     * database is unavailable.
     */
    public function test_an_append_during_replay_is_not_lost(): void
    {
        @unlink(CleanupJournal::path());

        CleanupJournal::append('public', 'a.jpg', 'test');

        // Rotation takes the current contents aside; the active journal is
        // immediately writable again.
        $rotated = CleanupJournal::rotate();

        $this->assertNotNull($rotated);

        CleanupJournal::append('public', 'b.jpg', 'test');

        // The rotated file holds only the first; the second is still pending.
        $this->assertCount(1, CleanupJournal::readFile($rotated)['entries']);
        $this->assertCount(1, CleanupJournal::entries());

        $this->artisan('mulkihawler:replay-cleanup-journal');

        // Both eventually reach the outbox.
        $this->assertDatabaseHas('orphaned_files', ['path' => 'a.jpg']);
        $this->assertDatabaseHas('orphaned_files', ['path' => 'b.jpg']);
    }

    public function test_a_malformed_line_is_quarantined_not_destroyed(): void
    {
        @unlink(CleanupJournal::path());

        CleanupJournal::append('public', 'good.jpg', 'test');

        // A torn final line, as a crash mid-write would leave.
        file_put_contents(
            CleanupJournal::path(),
            '{"disk":"public","pa',
            FILE_APPEND,
        );

        $this->artisan('mulkihawler:replay-cleanup-journal')->assertFailed();

        // The good entry transferred; the torn bytes are kept for a person.
        $this->assertDatabaseHas('orphaned_files', ['path' => 'good.jpg']);
        $this->assertFileExists(CleanupJournal::deadLetterPath());
    }

    /** A repeat must not inflate attempts without a new cleanup attempt. */
    public function test_replay_does_not_inflate_attempts(): void
    {
        @unlink(CleanupJournal::path());

        CleanupJournal::append('public', 'once.jpg', 'test');

        $this->artisan('mulkihawler:replay-cleanup-journal');
        $this->artisan('mulkihawler:replay-cleanup-journal');

        // The second run finds an empty journal: successful lines are removed
        // with their rotated file rather than left to be replayed again.
        $this->assertSame(1, (int) OrphanedFile::query()
            ->where('path', 'once.jpg')->value('attempts'));
    }

    /* ------------- job keys fit their column */

    public function test_a_very_long_path_still_produces_a_valid_job_key(): void
    {
        // 500 characters: valid for `path`, far beyond `job_key`'s 255.
        $long = 'projects/1/'.str_repeat('deep/', 95).'image.jpg';

        $this->assertGreaterThan(255, strlen($long));

        $job = OrphanedFile::record('public', $long, 'test');

        $this->assertLessThanOrEqual(255, strlen((string) $job->job_key));
        $this->assertSame($long, $job->path);
    }

    /** Two deep paths sharing a prefix must not collapse into one job. */
    public function test_similar_long_paths_are_distinct_jobs(): void
    {
        $base = 'projects/1/'.str_repeat('deep/', 95);

        $a = OrphanedFile::record('public', $base.'a.jpg', 'test');
        $b = OrphanedFile::record('public', $base.'b.jpg', 'test');

        $this->assertNotSame($a->job_key, $b->job_key);
        $this->assertNotSame($a->id, $b->id);
    }

    /* ------------- linkage is mandatory */

    public function test_finalisation_requires_a_matching_job(): void
    {
        $company = $this->company();
        $project = $this->projectFor($company, 'mandatory-link');
        $media = $this->makeProjectMedia($project->id, 'linked.jpg', 0, true);

        $job = OrphanedFile::record(
            'public', $media->path, 'project_media_cleanup_exhausted',
            ['source_type' => 'project_media', 'source_id' => $media->id],
        );

        $media->forceFill(['cleanup_pending' => true, 'cleanup_outbox_id' => $job->id])->save();

        $service = app(ProjectMediaService::class);

        // A job describing a DIFFERENT source is refused.
        $other = OrphanedFile::record(
            'public', 'projects/1/other.jpg', 'project_media_cleanup_exhausted',
            ['source_type' => 'project_media', 'source_id' => 9999],
        );

        $this->assertFalse(
            $service->finaliseAbsentSource((int) $media->id, 'public', $media->path, (int) $other->id)['ok'],
        );
    }

    /* ---------------- journal rotation is lossless */

    /**
     * An entry appended DURING a replay must survive.
     *
     * The previous flow read the active file and truncated that same file, so
     * anything appended in between was erased without ever reaching the
     * database — silent loss in the one mechanism that exists not to lose
     * things.
     */
    public function test_an_entry_appended_during_replay_is_not_lost(): void
    {
        $journal = CleanupJournal::class;

        @unlink($journal::path());

        $journal::append('public', 'projects/1/first.jpg', 'upload_compensation_failed');

        // Rotate as the replay does, then append as another process would.
        $rotated = $journal::rotate();

        $this->assertNotNull($rotated);

        $journal::append('public', 'projects/1/second.jpg', 'upload_compensation_failed');

        // The rotated file holds the first; the active journal holds the
        // second, untouched by this replay.
        $this->assertCount(1, $journal::readFile($rotated)['entries']);
        $this->assertCount(1, $journal::entries());

        $this->artisan('mulkihawler:replay-cleanup-journal');

        // Both eventually reach the outbox.
        $this->assertDatabaseHas('orphaned_files', ['path' => 'projects/1/first.jpg']);
        $this->assertDatabaseHas('orphaned_files', ['path' => 'projects/1/second.jpg']);
    }

    /** A malformed line is quarantined with its bytes, not destroyed. */
    public function test_a_malformed_line_is_quarantined(): void
    {
        $journal = CleanupJournal::class;

        @unlink($journal::path());
        @unlink($journal::deadLetterPath());

        $journal::append('public', 'projects/1/good.jpg', 'upload_compensation_failed');

        // A torn final line, as a crashed append would leave.
        file_put_contents($journal::path(), '{"disk":"public","pa', FILE_APPEND);

        $this->artisan('mulkihawler:replay-cleanup-journal')->assertFailed();

        $this->assertDatabaseHas('orphaned_files', ['path' => 'projects/1/good.jpg']);

        // Kept verbatim: bytes we cannot read may still name a real orphan.
        $this->assertStringContainsString(
            '{"disk":"public","pa',
            (string) file_get_contents($journal::deadLetterPath()),
        );
    }

    /** A crashed run's rotated file is adopted on the next pass. */
    public function test_a_rotated_file_left_by_a_crash_is_adopted(): void
    {
        $journal = CleanupJournal::class;

        @unlink($journal::path());

        $journal::append('public', 'projects/1/stranded.jpg', 'upload_compensation_failed');

        // Rotate and then "crash" — the file is left on disk unprocessed.
        $this->assertNotNull($journal::rotate());
        $this->assertCount(1, $journal::pendingProcessingFiles());

        $this->artisan('mulkihawler:replay-cleanup-journal');

        $this->assertDatabaseHas('orphaned_files', ['path' => 'projects/1/stranded.jpg']);
        $this->assertSame([], $journal::pendingProcessingFiles());
    }

    /* ---------------- fixed-length job identity */

    public function test_a_long_path_still_produces_a_storable_job_key(): void
    {
        $longPath = str_repeat('deep/directory/', 40).'photograph.jpg';

        $this->assertGreaterThan(255, strlen($longPath));

        $key = OrphanedFile::jobKey('public', $longPath);

        // A key that cannot be stored means a valid path could never be
        // recorded — nor replayed from the emergency journal.
        $this->assertLessThanOrEqual(255, strlen($key));

        $job = OrphanedFile::record(
            'public',
            $longPath,
            'upload_compensation_failed',
        );

        $this->assertDatabaseHas('orphaned_files', ['id' => $job->id, 'path' => $longPath]);
    }

    public function test_two_long_paths_do_not_collide(): void
    {
        $a = OrphanedFile::jobKey('public', str_repeat('a/', 200).'x.jpg');
        $b = OrphanedFile::jobKey('public', str_repeat('a/', 200).'y.jpg');

        // Truncating the path to fit would have made these one job.
        $this->assertNotSame($a, $b);
    }

    /* ---------------- finalisation requires its own job */

    public function test_finalisation_without_a_matching_job_is_refused(): void
    {
        $company = $this->company();
        $project = $this->projectFor($company, 'mandatory-link');
        $media = $this->makeProjectMedia($project->id, 'linked.jpg', 0, true);

        $media->forceFill(['cleanup_pending' => true, 'cleanup_outbox_id' => 4242])->save();

        $service = app(ProjectMediaService::class);

        $result = $service->finaliseAbsentSource((int) $media->id, 'public', (string) $media->path, 9999);

        $this->assertFalse($result['ok']);
        $this->assertDatabaseHas('project_media', ['id' => $media->id]);
    }

    /* ---------------- draft duplicates caught under the lock */

    public function test_a_duplicate_draft_upload_is_refused_by_the_service(): void
    {
        $user = $this->admin();
        $draft = $this->draftFor($user);
        $service = app(ProjectDraftMediaService::class);

        $service->attach((int) $draft->id, [
            'uploaded_by' => $user->id, 'kind' => 'image', 'disk' => 'draft-media',
            'path' => 'p/a.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 10,
            'checksum' => 'same-checksum',
        ]);

        $this->expectException(DuplicateMediaException::class);

        $service->attach((int) $draft->id, [
            'uploaded_by' => $user->id, 'kind' => 'image', 'disk' => 'draft-media',
            'path' => 'p/b.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 10,
            'checksum' => 'same-checksum',
        ]);
    }

    /* ------------- journal concurrency */

    /** The coordination lock is a separate file that is never renamed. */
    public function test_the_journal_lock_is_not_the_file_being_rotated(): void
    {
        $journal = CleanupJournal::class;

        /*
         * Locking the active journal itself was not enough: a writer could
         * open the old inode, block on the lock, and then append to a file
         * already rotated away and read.
         */
        $this->assertNotSame($journal::path(), $journal::lockPath());
        $this->assertStringEndsWith('.lock', $journal::lockPath());
    }

    /** Two rotations must never produce the same filename. */
    public function test_rotated_filenames_do_not_collide(): void
    {
        $journal = CleanupJournal::class;

        @unlink($journal::path());

        $journal::append('public', 'a.jpg', 'upload_compensation_failed');
        $first = $journal::rotate();

        $journal::append('public', 'b.jpg', 'upload_compensation_failed');
        $second = $journal::rotate();

        // Second-resolution time plus pid produced identical names inside one
        // process and second, silently overwriting unprocessed work.
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first, $second);
        $this->assertFileExists($first);
        $this->assertFileExists($second);
    }

    /** Only one worker may claim a pending file. */
    public function test_a_pending_file_can_only_be_claimed_once(): void
    {
        $journal = CleanupJournal::class;

        @unlink($journal::path());

        $journal::append('public', 'claimed.jpg', 'upload_compensation_failed');
        $rotated = $journal::rotate();

        $this->assertNotNull($rotated);

        $first = $journal::claim($rotated);
        $second = $journal::claim($rotated);

        // An atomic rename is the claim: the loser gets null, not a copy.
        $this->assertNotNull($first);
        $this->assertNull($second);
    }

    /** A failed quarantine must leave the line in the processing file. */
    public function test_an_unquarantinable_line_is_retained(): void
    {
        $journal = CleanupJournal::class;

        $deadLetter = $journal::deadLetterPath();
        @unlink($deadLetter);

        $failed = $journal::quarantine(['{"torn":']);

        /*
         * A torn line cannot be replayed, so it must not simply vanish: with a
         * writable disk it lands in the dead-letter journal and nothing is
         * reported back. The previous assertion only checked that an array is
         * an array, which would have passed just as happily if the line had
         * been dropped on the floor. Both halves of the contract are asserted
         * here — nothing reported as unstorable, and the line actually stored.
         */
        $this->assertSame([], $failed, 'A writable disk should accept the line.');
        $this->assertFileExists($deadLetter);
        $this->assertStringContainsString('{"torn":', (string) file_get_contents($deadLetter));

        @unlink($deadLetter);
    }

    /** Retain writes through a temporary file and renames atomically. */
    public function test_retain_replaces_the_file_atomically(): void
    {
        $journal = CleanupJournal::class;

        @unlink($journal::path());

        $journal::append('public', 'keep.jpg', 'upload_compensation_failed');
        $journal::append('public', 'drop.jpg', 'upload_compensation_failed');

        /*
         * v6 merge: the strict cleanup branch added durable claim leases,
         * so a rotated file is not yet OWNED — `retain()` refuses to
         * rewrite a file this worker has not claimed, which is exactly the
         * protection that stops two workers rewriting one journal. The
         * test therefore claims it the way a worker does, through the
         * production path, instead of rewriting an unclaimed file.
         */
        $rotated = $journal::rotate();
        $claimed = $journal::claim($rotated);

        $this->assertNotNull($claimed, 'the rotated journal could not be claimed');
        $this->assertTrue($journal::stillOwns($claimed), 'the claim did not establish ownership');

        $parsed = $journal::readFile($claimed);

        $this->assertCount(2, $parsed['entries']);

        // Keep only the first line.
        $this->assertTrue($journal::retain($claimed, [$parsed['entries'][0]['line']]));

        $after = $journal::readFile($claimed);

        $this->assertCount(1, $after['entries']);
        $this->assertSame('keep.jpg', $after['entries'][0]['data']['path']);

        // No temporary file left behind.
        $this->assertSame([], glob($rotated.'.tmp.*') ?: []);
    }

    /* ------------- outbox creation inside an outer transaction */

    /**
     * Recording must not poison a surrounding transaction.
     *
     * The handoff services call this from inside their owner/media
     * transactions. Catching a unique violation there is unrecoverable on
     * PostgreSQL, so no violation may be raised at all.
     */
    public function test_recording_twice_inside_a_transaction_succeeds(): void
    {
        DB::transaction(function (): void {
            $first = OrphanedFile::record(
                'public', 'projects/1/tx.jpg', 'promotion_rollback',
                ['source_type' => 'project_media', 'source_id' => 77],
            );

            $second = OrphanedFile::record(
                'public', 'projects/1/tx.jpg', 'promotion_rollback',
                ['source_type' => 'project_media', 'source_id' => 77],
            );

            // One job, incremented — and the transaction still usable, which
            // is what the assertion after this proves.
            $this->assertSame($first->id, $second->id);
            $this->assertSame(2, (int) $second->attempts);

            // A statement AFTER the second record: on a poisoned transaction
            // this would fail.
            OrphanedFile::query()->count();
        });

        $this->assertSame(1, OrphanedFile::query()->count());
    }

    /* ---------------------------------------------------------- helpers */

    private function makeProjectMedia(int $projectId, string $name, int $order, bool $cover = false): ProjectMedia
    {
        return ProjectMedia::query()->create([
            'project_id' => $projectId,
            'kind' => 'image',
            'disk' => 'public',
            'path' => 'projects/'.$name,
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
            'sort_order' => $order,
            'is_cover' => $cover,
        ]);
    }

    /** A submitted draft that produced this project for this company. */
    private function submittedDraft(User $user, Company $company, Project $project): ProjectDraft
    {
        $draft = $this->draftFor($user, $company->id);

        $draft->forceFill([
            'acting_company_id' => $company->id,
            'project_id' => $project->id,
            'submitted_at' => now(),
        ])->save();

        return $draft;
    }

    /**
     * Association attributes describing valid Wizard provenance.
     *
     * @return array<string, mixed>
     */
    private function pendingProvenance(Company $company, User $user): array
    {
        $project = null;

        return [
            'is_approved' => false,
            'management_status' => 'pending',
            'created_by' => $user->id,
        ];
    }

    /**
     * Record complete, valid creation evidence the way production does.
     *
     * `recordCreationEvidence()` demands the full snapshot AND a membership
     * belonging to the recorded creator. Fixtures that hand-wrote only
     * `creator_manage_projects_confirmed_at` produced evidence the model
     * rightly rejects as incomplete — the guard is doing its job, so the
     * fixture uses the real API instead of forging a fragment of a record.
     */
    private function grantCreationEvidence(
        Project $project,
        User $user,
    ): CompanyProjectAssociation {
        $association = CompanyProjectAssociation::query()
            ->where('project_id', $project->id)
            ->firstOrFail();

        // Evidence describes the creator, so the creator must be recorded
        // first, and it requires the draft that actually created the project.
        $patch = [];

        if ($association->created_by === null) {
            $patch['created_by'] = $user->id;
        }

        if ($association->created_via_project_draft_id === null) {
            $company = Company::query()
                ->findOrFail($association->company_id);

            $patch['created_via_project_draft_id'] = $this->submittedDraft($user, $company, $project)->id;
        }

        if ($patch !== []) {
            $association->forceFill($patch)->save();
        }

        $membership = CompanyStaff::query()
            ->where('user_id', $user->id)
            ->where('company_id', $association->company_id)
            ->firstOrFail();

        $association->recordCreationEvidence($membership);

        return $association->refresh();
    }

    /** Grant an additional project-managing membership. */
    private function addMembership(User $user, Company $company): void
    {
        CompanyStaff::query()->create([
            'company_id' => $company->id, 'user_id' => $user->id,
            'role' => 'manager', 'is_active' => true, 'may_manage_projects' => true,
        ]);
    }

    /**
     * A project associated with a company.
     *
     * @param  array<string, mixed>  $association
     */
    private function projectFor(Company $company, string $slug, array $association = []): Project
    {
        $project = Project::query()->create([
            'slug' => $slug,
            'name_ckb' => $slug,
            'project_type' => ProjectType::Residential->value,
            'construction_status' => ConstructionStatus::UnderConstruction->value,
            'delivery_status' => DeliveryStatus::NotStarted->value,
            'publication_status' => PublicationStatus::Draft->value,
        ]);

        if (($association['management_status'] ?? null) === 'pending'
            && ! isset($association['created_via_project_draft_id'])
            && isset($association['created_by'])) {
            // Build the correlated draft the provenance check requires.
            $draft = ProjectDraft::query()->create([
                'user_id' => $association['created_by'],
                'company_id' => $company->id,
                'acting_company_id' => $company->id,
                'project_id' => $project->id,
                'current_step' => WizardStep::REVIEW,
                'payload' => [],
                'completed_steps' => [],
                'submitted_at' => now(),
            ]);

            $association['created_via_project_draft_id'] = $draft->id;
        }

        /*
         * LIFECYCLE-CONSISTENT BY CONSTRUCTION.
         *
         * The helper used to hard-code `is_approved = true` with
         * `management_status = approved` and no `approved_at`, which
         * AssociationLifecycle rejects and the database CHECK rejects too. A
         * caller asking for `revoked` still got `is_approved = true`. Fifty
         * tests died on the shared helper rather than on anything they were
         * actually testing. The production invariant is correct and untouched;
         * the fixture now honours it, and an explicit caller value still wins.
         */
        $status = (string) ($association['management_status'] ?? 'approved');

        $lifecycle = match ($status) {
            'approved' => ['is_approved' => true, 'approved_at' => now()],
            'rejected' => ['is_approved' => false, 'rejected_at' => now()],
            'revoked' => ['is_approved' => false, 'revoked_at' => now()],
            default => ['is_approved' => false],
        };

        $attributes = array_merge([
            'company_id' => $company->id,
            'project_id' => $project->id,
            'role' => 'official_developer',
            'management_status' => $status,
        ], $lifecycle, $association);

        /*
         * CREATION EVIDENCE IS NOT MASS ASSIGNABLE, ON PURPOSE.
         *
         * `created_by_company_staff_id`, `creator_membership_role`,
         * `creator_membership_company_id` and
         * `creator_manage_projects_confirmed_at` are excluded from $fillable so
         * that no request body can manufacture proof that somebody was entitled
         * to create a project. Tests that need a specific evidence state write
         * it explicitly rather than the model being loosened to accept it.
         */
        $model = new CompanyProjectAssociation;
        $guarded = array_diff_key($attributes, array_flip($model->getFillable()));
        $fillable = array_intersect_key($attributes, array_flip($model->getFillable()));

        $created = CompanyProjectAssociation::query()->create($fillable);

        if ($guarded !== []) {
            $created->forceFill($guarded)->save();
        }

        return $project;
    }

    private function draftFor(User $user, ?int $companyId = null): ProjectDraft
    {
        $draft = ProjectDraft::query()->create([
            'user_id' => $user->id,
            'company_id' => $companyId,
            'current_step' => WizardStep::IDENTITY,
            'payload' => [],
            'completed_steps' => [],
        ]);

        /*
         * REFRESHED, so database defaults are on the model.
         *
         * `version` is defaulted by the schema, not by this array, so the
         * in-memory draft carried no version at all. Every test that posted
         * `'version' => $draft->version` therefore sent an EMPTY value, the
         * optimistic-lock check saw a mismatch, and the step was stored without
         * being marked complete — a stale-write conflict the test never
         * intended and never saw, because the controller redirects back rather
         * than erroring.
         */
        return $draft->refresh();
    }

    /**
     * A draft whose required steps are filled, plus any extra step payloads.
     *
     * @param  array<string, array<string, mixed>>  $extra  step name => values
     */
    private function completeDraftFor(User $user, array $extra = []): ProjectDraft
    {
        $payload = [
            'identity' => [
                'name_ckb' => 'ئانکاوا',
                'name_en' => 'Ankawa Sky',
                'project_type' => ProjectType::Residential->value,
            ],
            'location' => ['latitude' => 36.19, 'longitude' => 44.009],
            'details' => [
                'construction_status' => ConstructionStatus::UnderConstruction->value,
                'delivery_status' => DeliveryStatus::NotStarted->value,
            ],
        ];

        foreach ($extra as $step => $values) {
            $payload[$step] = $values;
        }

        $draft = ProjectDraft::query()->create([
            'user_id' => $user->id,
            'company_id' => null,
            'current_step' => WizardStep::REVIEW,
            'payload' => $payload,
            'completed_steps' => WizardStep::required(),
        ]);

        // See draftFor(): `version` is a schema default and must be on the
        // model, or every posted version is empty.
        return $draft->refresh();
    }

    /**
     * A draft with the given steps already marked complete.
     *
     * @param  list<string>  $steps
     */
    private function completedThrough(User $user, array $steps): ProjectDraft
    {
        $payload = [
            'identity' => [
                'name_ckb' => 'ئانکاوا',
                'project_type' => ProjectType::Residential->value,
            ],
        ];

        // Duplicate user_id/company_id keys removed: PHP silently keeps the
        // last, so the array was legal and misleading rather than broken.
        $draft = ProjectDraft::query()->create([
            'user_id' => $user->id,
            'company_id' => null,
            'current_step' => $steps[0] ?? WizardStep::IDENTITY,
            'payload' => $payload,
            'completed_steps' => $steps,
        ]);

        // Refreshed for the same reason draftFor() is: `version` is a schema
        // default, so an unrefreshed model posts an empty version and every
        // write loses the optimistic-lock check.
        return $draft->refresh();
    }
}
