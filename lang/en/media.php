<?php

declare(strict_types=1);

return [
    'title' => 'Images',
    'upload' => 'Upload image',
    'upload_hint' => 'JPEG, PNG, WebP or AVIF',
    'alt_text' => 'Alt text',
    'alt_hint' => 'Describe the image for anyone who cannot see it',
    'alt_required' => 'Required',
    'missing_alt_notice' => 'Images without alt text:',
    'credit' => 'Image credit',
    'empty' => 'No images yet',
    'empty_hint' => 'Add images for this project',
    'set_cover' => 'Set as cover',
    'cover' => 'Cover image',
    'image' => 'Image',
    'pending_review' => 'Uploaded and awaiting review',
    'offer_upload_hint' => 'Images are reviewed before they appear',
    'none_approved_notice' => 'No image is approved, so no picture is shown to a buyer',
    'queue' => 'Image queue',
    'queue_empty' => 'No images awaiting review',
    'queue_empty_hint' => 'New uploads appear here',
    'pending_count' => 'Images awaiting review:',

    'moderation' => [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ],

    'errors' => [
        'pending_cleanup' => 'That media is being deleted',
        'not_found' => 'That media could not be found',
        'cleanup_failed' => 'The file could not be removed',
        'upload_failed' => 'The upload failed',
        'mime_not_allowed' => 'That file type is not allowed',
        'extension_blocked' => 'That extension is blocked',
        'too_large' => 'The file is too large',
        'not_a_real_image' => 'That file is not an image',
        'storage_failed' => 'Storage failed',
        'duplicate' => 'That image is already uploaded',
    ],
];
