<?php

declare(strict_types=1);

namespace App\Modules\Advisor\Exceptions;

use RuntimeException;

/**
 * A provider failure.
 *
 * Deliberately carries no response body. A provider error frequently echoes
 * the request, which for this product may include a user's stated budget and
 * their children's school locations — spec 32.2 keeps that out of logs.
 */
final class AiProviderException extends RuntimeException
{
    public static function notConfigured(string $provider): self
    {
        return new self(sprintf('AI provider "%s" is not configured.', $provider));
    }

    public static function timedOut(string $provider, int $seconds): self
    {
        return new self(sprintf('AI provider "%s" did not respond within %d seconds.', $provider, $seconds));
    }

    public static function costLimitReached(string $limit): self
    {
        return new self(sprintf('The configured monthly AI cost limit of %s USD has been reached.', $limit));
    }

    public static function rejected(string $provider, int $status): self
    {
        return new self(sprintf('AI provider "%s" rejected the request with status %d.', $provider, $status));
    }

    /**
     * The endpoint could not be reached at all.
     *
     * No underlying message is carried: a transfer exception includes the full
     * request URL, and for some gateways the API key sits in that path.
     */
    public static function unreachable(string $provider): self
    {
        return new self(sprintf('AI provider "%s" could not be reached.', $provider));
    }

    /** 401/403 — the credential is wrong or revoked, and retrying will not help. */
    public static function unauthorized(string $provider): self
    {
        return new self(sprintf('AI provider "%s" refused the credential.', $provider));
    }

    /**
     * 429 — distinguished from a generic failure because it is the one error
     * where falling back to a second provider is the correct response rather
     * than merely a tolerable one.
     */
    public static function rateLimited(string $provider): self
    {
        return new self(sprintf('AI provider "%s" rate-limited the request.', $provider));
    }

    public static function failed(string $provider, int $status): self
    {
        return self::rejected($provider, $status);
    }

    /** The request was malformed before it left this application. */
    public static function invalidRequest(string $provider, string $reason): self
    {
        return new self(sprintf('Invalid request for AI provider "%s": %s.', $provider, $reason));
    }

    /**
     * The provider answered, but not in the shape the contract promises.
     *
     * The reason is a fixed description chosen by this application, never the
     * provider's body — see the class docblock.
     */
    public static function invalidResponse(string $provider, string $reason): self
    {
        return new self(sprintf('AI provider "%s" returned an unusable response: %s.', $provider, $reason));
    }
}
