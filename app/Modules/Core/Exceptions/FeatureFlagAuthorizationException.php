<?php

declare(strict_types=1);

namespace App\Modules\Core\Exceptions;

use RuntimeException;

final class FeatureFlagAuthorizationException extends RuntimeException
{
    public static function requiresSuperAdmin(string $flag): self
    {
        return new self(sprintf(
            'Feature flag "%s" has commercial, privacy or legal consequences and may only be changed by a Super Admin.',
            $flag,
        ));
    }
}
