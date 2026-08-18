<?php

declare(strict_types=1);

namespace App\Modules\Companies\Support;

/**
 * The company data boundary (spec 37.4: "Company cannot access unauthorized
 * leads", spec 3.1 company account types).
 *
 * A company portal user is not an administrator with a narrower menu. They are
 * an outside party with access to a slice of the platform's data, and the
 * slice must be defined by an explicit rule rather than by whichever `where`
 * clause a controller happened to include.
 *
 * Deny by default throughout. Every method answers "may this company see this
 * specific record", and an absent or ambiguous ownership marker is a refusal,
 * never a pass.
 */
final class CompanyScope
{
    /**
     * A lead is visible to a company only when it was explicitly routed to
     * that company AND the user consented to being contacted by them.
     *
     * Both conditions matter. Routing without consent is a privacy breach
     * (spec 23.3, 30.2: no marketing contact without valid consent); consent
     * without routing would expose every consenting user to every company.
     *
     * @param  array{company_id?: int|null, consent_company_contact?: bool, is_anonymised?: bool}  $lead
     * @return array{allowed: bool, reason: string|null}
     */
    public function mayViewLead(int $companyId, array $lead): array
    {
        if (($lead['company_id'] ?? null) !== $companyId) {
            return ['allowed' => false, 'reason' => 'lead_not_routed_to_company'];
        }

        if (($lead['consent_company_contact'] ?? false) !== true) {
            return ['allowed' => false, 'reason' => 'no_company_contact_consent'];
        }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * Contact details are a narrower permission than the lead itself.
     *
     * A company may legitimately see that an anonymised enquiry exists — it is
     * their own demand signal — without being handed a phone number. Spec 32.2
     * keeps identifiers out of anything that does not strictly need them.
     *
     * @param  array<string, mixed>  $lead
     * @return array{allowed: bool, reason: string|null}
     */
    public function mayViewLeadContactDetails(int $companyId, array $lead): array
    {
        $base = $this->mayViewLead($companyId, $lead);

        if (! $base['allowed']) {
            return $base;
        }

        if (($lead['is_anonymised'] ?? false) === true) {
            return ['allowed' => false, 'reason' => 'lead_is_anonymised'];
        }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * A company may edit only its own offers, and only while the offer is in a
     * state it is permitted to touch. Once submitted for moderation the offer
     * belongs to the review queue.
     *
     * @param  array{company_id?: int|null, status?: string}  $offer
     * @return array{allowed: bool, reason: string|null}
     */
    public function mayEditOffer(int $companyId, array $offer): array
    {
        if (($offer['company_id'] ?? null) !== $companyId) {
            return ['allowed' => false, 'reason' => 'offer_belongs_to_another_company'];
        }

        $editable = ['draft', 'changes_requested', 'rejected', 'expired'];

        if (! in_array($offer['status'] ?? '', $editable, true)) {
            return ['allowed' => false, 'reason' => 'offer_is_under_moderation'];
        }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * Project association is admin-controlled (spec 37.4). A company may
     * request one; it may never grant itself one.
     *
     * @return array{allowed: bool, reason: string|null}
     */
    public function mayGrantProjectAssociation(bool $actorIsAdministrator): array
    {
        return $actorIsAdministrator
            ? ['allowed' => true, 'reason' => null]
            : ['allowed' => false, 'reason' => 'association_is_admin_controlled'];
    }

    /**
     * Filter a lead set to what this company may actually see, reporting the
     * count withheld so the portal can say "3 enquiries are awaiting consent"
     * rather than silently showing fewer.
     *
     * @param  list<array<string, mixed>>  $leads
     * @return array{visible: list<array<string, mixed>>, withheld: int, reasons: array<string, int>}
     */
    public function filterLeads(int $companyId, array $leads): array
    {
        $visible = [];
        $reasons = [];

        foreach ($leads as $lead) {
            $verdict = $this->mayViewLead($companyId, $lead);

            if ($verdict['allowed']) {
                $visible[] = $lead;

                continue;
            }

            $reason = (string) $verdict['reason'];
            $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
        }

        return [
            'visible' => $visible,
            'withheld' => count($leads) - count($visible),
            'reasons' => $reasons,
        ];
    }
}
