<?php

declare(strict_types=1);

namespace App\Modules\Projects\Exceptions;

use RuntimeException;

/**
 * The same file, by checksum, is already attached to this project.
 *
 * A distinct type because a duplicate is an ORDINARY outcome and a storage
 * failure is not. Signalling both with a generic RuntimeException meant an
 * editor uploading the same render twice was told the upload had broken, and
 * the log agreed with them.
 */
final class DuplicateMediaException extends RuntimeException
{
    public function __construct(string $message = 'duplicate')
    {
        parent::__construct($message);
    }
}
