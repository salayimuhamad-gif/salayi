<?php

declare(strict_types=1);

return [
    'create' => 'Create offer',
    'empty' => 'No offers yet',
    'empty_hint' => 'Offers appear here',
    'awaiting_moderation' => 'Awaiting moderation',
    'awaiting_hint' => 'Cannot be published without a moderator approving',
    'published_offers' => 'Published',
    'queue_only' => 'Moderation queue only',
    'no_company' => 'No company',
    'history' => 'Status history',
    'as_moderator' => 'as moderator',

    'public' => [
        'title' => 'Offers',
        'search' => 'Search by name…',
        'budget' => 'Max budget',
        'none' => 'No offers found',
        'none_hint' => 'Try changing the filters',
        'terms' => 'Terms',
    ],

    'fields' => [
        'title_ckb' => 'Title (Sorani)',
        'title_en' => 'Title (English)',
        'offer_type' => 'Offer type',
        'company_hint' => '⚠ marks an unverified company',
        'precision' => 'Location precision',
        'details' => 'Details',
        'price' => 'Price',
        'rooms' => 'Rooms',
        'sponsored_hint' => 'Payment does not buy an organic position',
    ],
    'precision' => [
        'exact' => 'Exact',
        'approximate' => 'Approximate',
        'area_only' => 'Area only',
    ],

    'statuses' => [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'under_review' => 'Under review',
        'changes_requested' => 'Changes requested',
        'approved' => 'Approved',
        'scheduled' => 'Scheduled',
        'published' => 'Published',
        'paused' => 'Paused',
        'expired' => 'Expired',
        'rejected' => 'Rejected',
        'archived' => 'Archived',
    ],
    'offer_types' => [
        'sale' => 'For sale',
        'rent' => 'For rent',
        'investment' => 'Investment',
        'pre_launch' => 'Pre-launch',
    ],
    'sponsorship' => [
        'sponsored' => 'Sponsored',
        'paid_placement' => 'Paid placement',
        'organic_results' => 'Organic results',
        'sponsored_section' => 'Sponsored section',
        'disclosure_notice' => 'These results are paid for and are separated from organic results',
        'not_ranked_by_payment' => 'Organic results are not ranked by payment',
    ],
    'location_precision' => [
        'exact' => 'Exact location',
        'approximate' => 'Approximate location',
        'area_only' => 'Area only',
    ],
    'errors' => [
        'project_not_eligible' => 'That project does not belong to your company',
        'owner_required' => 'A company is required',
        'exact_needs_coordinates' => 'An exact location requires coordinates',
        'illegal_transition' => 'That status change is not allowed',
        'moderator_required' => 'Only a moderator may do that',
        'missing_disclosure' => 'A disclosure label is required',
        'offer_under_moderation' => 'This offer is under moderation and cannot be edited',
    ],
];
