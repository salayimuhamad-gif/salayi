<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

/**
 * Every user reference in the schema, classified.
 *
 * The previous list held 22 pairs and a test that only proved those 22 existed.
 * That is one-directional: it could never notice a user-owned column that was
 * never added to the list, and a missed one means an account gets anonymized
 * and deleted while it still owns something. So the contract is now the whole
 * set, split in two, and a test fails when the schema grows a reference that
 * appears in neither group.
 *
 * BLOCK_RECLAMATION — a row here means the account has DONE something. It is
 * not abandoned, whatever its age, and reclamation must refuse.
 *
 * INTENTIONALLY_IGNORED — a row here does NOT make an account non-abandoned.
 * Each carries its reason. They fall into three families: moderation metadata
 * recording what this user did to somebody ELSE's content (the reviewer owns
 * nothing); audit and release history, which is retained deliberately and
 * survives the tombstone; and short-lived session or login material that is
 * not content at all.
 */
final class UserReferenceContract
{
    /**
     * @var array<int, array{0: string, 1: string}>
     */
    public const BLOCK_RECLAMATION = [
        ['advisor_conversations', 'user_id'],
        ['alert_subscriptions', 'user_id'],
        ['areas', 'created_by'],
        ['branding_assets', 'uploaded_by'],
        ['company_branches', 'manager_user_id'],
        ['company_developer_associations', 'created_by'],
        ['company_project_associations', 'created_by'],
        ['company_staff', 'user_id'],
        ['content_items', 'author_id'],
        ['content_revisions', 'author_id'],
        ['demand_profiles', 'owner_user_id'],
        ['demand_profiles', 'user_id'],
        ['knowledge_events', 'author_id'],
        ['lead_notes', 'author_user_id'],
        ['lead_tasks', 'assigned_to_user_id'],
        ['lead_tasks', 'created_by_user_id'],
        ['lifestyle_profiles', 'user_id'],
        ['market_import_batches', 'created_by'],
        ['notification_preferences', 'user_id'],
        ['notifications', 'user_id'],
        ['offers', 'agent_user_id'],
        ['offers', 'owner_user_id'],
        ['orphaned_files', 'user_id'],
        ['phone_reveals', 'user_id'],
        ['places', 'created_by'],
        ['portfolio_properties', 'user_id'],
        ['price_records', 'created_by'],
        ['project_documents', 'uploaded_by'],
        ['project_draft_media', 'uploaded_by'],
        ['project_drafts', 'user_id'],
        ['project_media', 'uploaded_by'],
        ['project_prices', 'created_by'],
        ['project_rating_history', 'author_id'],
        ['project_ratings', 'author_id'],
        ['projects', 'created_by'],
        ['role_user', 'user_id'],
        ['saved_searches', 'user_id'],
    ];

    /**
     * @var array<int, array{0: string, 1: string, 2: string}>
     */
    public const INTENTIONALLY_IGNORED = [
        ['ad_campaigns', 'approved_by', 'moderation metadata; the approver owns nothing here'],
        ['advisor_prompts', 'approved_by', 'moderation metadata; the approver owns nothing here'],
        ['areas', 'reviewed_by', 'moderation metadata about someone ELSE\'s content; the reviewer owns nothing here'],
        ['companies', 'verified_by', 'moderation metadata; the verifier owns nothing here'],
        ['company_developer_associations', 'approved_by', 'moderation metadata; the approver owns nothing here'],
        ['company_developer_associations', 'rejected_by', 'moderation metadata; the rejecter owns nothing here'],
        ['company_developer_associations', 'revoked_by', 'moderation metadata; the revoker owns nothing here'],
        ['company_project_associations', 'approved_by', 'moderation metadata; the approver owns nothing here'],
        ['company_project_associations', 'rejected_by', 'moderation metadata; the rejecter owns nothing here'],
        ['company_project_associations', 'revoked_by', 'moderation metadata; the revoker owns nothing here'],
        ['consents', 'user_id', 'this account\'s own registration evidence; deleted with it'],
        ['content_items', 'reviewed_by', 'moderation metadata about someone ELSE\'s content; the reviewer owns nothing here'],
        ['feature_flags', 'updated_by', 'settings/flag audit metadata, retained deliberately'],
        ['glossary_terms', 'updated_by', 'settings/flag audit metadata, retained deliberately'],
        ['knowledge_events', 'reviewed_by', 'moderation metadata about someone ELSE\'s content; the reviewer owns nothing here'],
        ['lead_scores', 'overridden_by', 'moderation metadata; the overrider owns nothing here'],
        ['offers', 'reviewed_by', 'moderation metadata about someone ELSE\'s content; the reviewer owns nothing here'],
        ['places', 'reviewed_by', 'moderation metadata about someone ELSE\'s content; the reviewer owns nothing here'],
        ['price_records', 'reviewed_by', 'moderation metadata about someone ELSE\'s content; the reviewer owns nothing here'],
        ['project_nearby_places', 'overridden_by', 'moderation metadata; the overrider owns nothing here'],
        ['project_ratings', 'reviewed_by', 'moderation metadata about someone ELSE\'s content; the reviewer owns nothing here'],
        ['projects', 'reviewed_by', 'moderation metadata about someone ELSE\'s content; the reviewer owns nothing here'],
        ['release_records', 'applied_by', 'release/audit history, retained deliberately'],
        ['sessions', 'user_id', 'session material, not content; expires on its own'],
        ['site_settings', 'updated_by', 'settings/flag audit metadata, retained deliberately'],
        ['telegram_login_intents', 'user_id', 'spent or expiring login material, never content'],
        ['telegram_return_handoffs', 'user_id', 'short-lived return links; worthless within minutes'],
        /*
         * Verification material, not content — so it belongs here and not in
         * BLOCK_RECLAMATION, on the same reasoning as its two neighbours: an
         * unpressed link is not something the account has DONE.
         *
         * It does still stop the sweep, but through a different and narrower
         * rule. AbandonedAccountPolicy::assess() refuses any account holding a
         * LIVE token, because deleting one would break the no-expiry promise
         * the token makes. A used or revoked token stops nothing, which is
         * right: it is spent material, and the row is deleted with the account
         * by the FK cascade.
         */
        ['telegram_verification_tokens', 'user_id', 'verification material, not content; a LIVE one blocks the sweep through the retention rule instead'],
        ['translations', 'reviewed_by', 'moderation metadata about someone ELSE\'s content; the reviewer owns nothing here'],
    ];

    /**
     * Every classified reference, as "table.column".
     *
     * @return array<int, string>
     */
    public static function classified(): array
    {
        $all = [];

        foreach (self::BLOCK_RECLAMATION as [$table, $column]) {
            $all[] = $table.'.'.$column;
        }

        foreach (self::INTENTIONALLY_IGNORED as [$table, $column]) {
            $all[] = $table.'.'.$column;
        }

        sort($all);

        return $all;
    }
}
