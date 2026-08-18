<?php

declare(strict_types=1);

return [
    'unsubscribe' => [
        'title' => 'Stop these notifications',
        'intro' => 'You can stop this kind of contact. Once you confirm, no further messages of this kind will be sent.',
        'confirm' => 'Yes, stop these',
        'done' => 'Stopped. You will not receive messages of this kind again.',
        'already' => 'This kind of contact is already switched off.',
        'resubscribe' => 'Turn back on',
        'resubscribed' => 'Turned back on.',
        'undo' => 'Undo',
        'invalid' => 'This link is not valid.',
        'invalid_hint' => 'Copy the whole link from the message, or change the setting from your account.',
        'transactional_notice' => 'Account security notices, moderation outcomes and legal notices are still sent, because they are not marketing.',
    ],

    'purposes' => [
        'alerts' => 'Search and listing alerts',
        'marketing' => 'Marketing messages',
        'company_contact' => 'Contact from companies',
        'portfolio_contact' => 'Contact about your properties',
        'telegram_message' => 'Telegram messages',
    ],

    'center' => [
        'title' => 'Notifications',
        'subtitle' => 'Everything that has been sent to you',
        'empty' => 'No notifications',
        'empty_hint' => 'When something important happens, it will appear here.',
        'empty_unread' => 'No unread notifications',
        'mark_read' => 'Mark as read',
        'mark_all_read' => 'Mark all as read',
        'marked_all' => 'All notifications marked as read.',
        'marked_one' => 'Marked as read.',
        'filter_all' => 'All',
        'filter_unread' => 'Unread',
        'unread_count' => ':count unread',
        'back' => 'Back to list',
        'received_at' => 'Received',
        'why_received' => 'Why you received this',
        'open_action' => 'Open',
        'detail_title' => 'Notification detail',
        'aria_bell' => 'Notifications',
        'aria_unread' => ':count unread notifications',
        'channel' => 'Channel',
        'not_found' => 'That notification could not be found.',
        'time_now' => 'Just now',
        'time_minutes' => ':count minutes ago',
        'time_hours' => ':count hours ago',
    ],

    'security_events' => [
        'mfa_enrolled' => 'two-factor authentication was enabled',
        'password_reset' => 'your password was changed',
    ],

    'review_status' => [
        'approved' => 'approved',
        'rejected' => 'rejected',
        'pending' => 'pending review',
    ],

    'priorities' => [
        'low' => 'Low',
        'normal' => 'Normal',
        'high' => 'High',
        'urgent' => 'Urgent',
    ],

    'events' => [
        'offer_expired' => [
            'subject' => 'Your listing has expired',
            'body' => 'The listing ":title" expired on :date and is no longer shown to users.',
            'reason' => 'You received this because you own this listing.',
        ],
        'offer_approved' => [
            'subject' => 'Your listing was approved',
            'body' => 'The listing ":title" was approved and can now be published.',
            'reason' => 'You received this because it is the outcome of the review of your listing.',
        ],
        'offer_rejected' => [
            'subject' => 'Your listing was rejected',
            'body' => 'The listing ":title" was rejected. Reason: :reason',
            'reason' => 'You received this because it is the outcome of the review of your listing.',
        ],
        'offer_changes_requested' => [
            'subject' => 'Changes requested',
            'body' => 'The listing ":title" needs changes before it can be approved. Note: :reason',
            'reason' => 'You received this because it is the outcome of the review of your listing.',
        ],
        'rating_reviewed' => [
            'subject' => 'Your rating was reviewed',
            'body' => 'Your rating for ":project" is now :status.',
            'reason' => 'You received this because you submitted this rating.',
        ],
        'account_security' => [
            'subject' => 'Account security notice',
            'body' => 'Something important happened on your account: :event',
            'reason' => 'You received this because it concerns the security of your account.',
        ],
        'digest' => [
            'subject' => 'Daily digest — :count notifications',
            'body' => 'This is a summary of the notifications from the last twenty-four hours.',
            'reason' => 'You received this because you chose the daily digest.',
        ],
    ],

    'digest' => [
        'heading' => 'Daily digest',
        'item' => '• :subject',
        'none_pending' => 'No notifications pending.',
        'sent' => ':count digest(s) sent.',
    ],

    'preferences' => [
        'title' => 'Notification settings',
        'description' => 'Choose how you receive notifications.',
        'frequency' => 'Frequency',
        'immediate' => 'Immediate',
        'daily' => 'Daily digest',
        'save' => 'Save',
        'never_batched_notice' => 'Moderation outcomes and account security notices are always sent immediately, even if you choose the daily digest.',
        'digest_hour' => 'Digest time',
        'saved' => 'Settings saved.',
    ],
];
