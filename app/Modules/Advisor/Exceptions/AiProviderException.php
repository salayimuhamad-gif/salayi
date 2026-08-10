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
 *
 * Every factory stamps a CATEGORY, because the failures demand different
 * responses: an exhausted budget must stop everything, a rate limit should
 * hand off to the fallback immediately, a refused credential will not fix
 * itself with retries, and a tripped breaker is a state rather than an
 * event. The category is a fixed token from this application's own
 * vocabulary — never provider text — so it is safe to log, to store, and to
 * show a Super Admin on the diagnostics surface.
 */
final class AiProviderException extends RuntimeException
{
    public const CATEGORY_NOT_CONFIGURED = 'not_configured';

    public const CATEGORY_AUTH_FAILED = 'auth_failed';

    public const CATEGORY_RATE_LIMITED = 'rate_limited';

    public const CATEGORY_UNREACHABLE = 'timeout_or_unreachable';

    public const CATEGORY_PROVIDER_ERROR = 'provider_error';

    public const CATEGORY_MODEL_UNAVAILABLE = 'model_unavailable';

    public const CATEGORY_INVALID_RESPONSE = 'invalid_response';

    public const CATEGORY_INVALID_REQUEST = 'invalid_request';

    public const CATEGORY_BUDGET_EXHAUSTED = 'budget_exhausted';

    public const CATEGORY_BREAKER_OPEN = 'breaker_open';

    private string $category = self::CATEGORY_PROVIDER_ERROR;

    private string $provider = 'none';

    public function category(): string
    {
        return $this->category;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    private static function make(string $message, string $category, string $provider): self
    {
        $exception = new self($message);
        $exception->category = $category;
        $exception->provider = $provider;

        return $exception;
    }

    public static function notConfigured(string $provider): self
    {
        return self::make(
            sprintf('AI provider "%s" is not configured.', $provider),
            self::CATEGORY_NOT_CONFIGURED,
            $provider,
        );
    }

    public static function timedOut(string $provider, int $seconds): self
    {
        return self::make(
            sprintf('AI provider "%s" did not respond within %d seconds.', $provider, $seconds),
            self::CATEGORY_UNREACHABLE,
            $provider,
        );
    }

    public static function costLimitReached(string $limit): self
    {
        return self::make(
            sprintf('The configured monthly AI cost limit of %s USD has been reached.', $limit),
            self::CATEGORY_BUDGET_EXHAUSTED,
            'none',
        );
    }

    public static function rejected(string $provider, int $status): self
    {
        return self::make(
            sprintf('AI provider "%s" rejected the request with status %d.', $provider, $status),
            self::CATEGORY_PROVIDER_ERROR,
            $provider,
        );
    }

    /**
     * The endpoint could not be reached at all.
     *
     * No underlying message is carried: a transfer exception includes the full
     * request URL, and for some gateways the API key sits in that path.
     */
    public static function unreachable(string $provider): self
    {
        return self::make(
            sprintf('AI provider "%s" could not be reached.', $provider),
            self::CATEGORY_UNREACHABLE,
            $provider,
        );
    }

    /** 401/403 — the credential is wrong or revoked, and retrying will not help. */
    public static function unauthorized(string $provider): self
    {
        return self::make(
            sprintf('AI provider "%s" refused the credential.', $provider),
            self::CATEGORY_AUTH_FAILED,
            $provider,
        );
    }

    /**
     * 429 — distinguished from a generic failure because it is the one error
     * where falling back to a second provider is the correct response rather
     * than merely a tolerable one.
     */
    public static function rateLimited(string $provider): self
    {
        return self::make(
            sprintf('AI provider "%s" rate-limited the request.', $provider),
            self::CATEGORY_RATE_LIMITED,
            $provider,
        );
    }

    public static function failed(string $provider, int $status): self
    {
        return self::rejected($provider, $status);
    }

    /**
     * 404 from a hosted provider whose base URL is fixed — which leaves the
     * model name as the thing that does not exist. Its own category so the
     * admin test-connection surface can say "model unavailable" instead of a
     * generic provider error.
     */
    public static function modelUnavailable(string $provider, string $model): self
    {
        return self::make(
            sprintf('AI provider "%s" does not recognise the configured model "%s".', $provider, $model),
            self::CATEGORY_MODEL_UNAVAILABLE,
            $provider,
        );
    }

    /** The request was malformed before it left this application. */
    public static function invalidRequest(string $provider, string $reason): self
    {
        return self::make(
            sprintf('Invalid request for AI provider "%s": %s.', $provider, $reason),
            self::CATEGORY_INVALID_REQUEST,
            $provider,
        );
    }

    /**
     * The provider answered, but not in the shape the contract promises.
     *
     * The reason is a fixed description chosen by this application, never the
     * provider's body — see the class docblock.
     */
    public static function invalidResponse(string $provider, string $reason): self
    {
        return self::make(
            sprintf('AI provider "%s" returned an unusable response: %s.', $provider, $reason),
            self::CATEGORY_INVALID_RESPONSE,
            $provider,
        );
    }

    /** The circuit breaker is holding this provider out of rotation. */
    public static function breakerOpen(string $provider): self
    {
        return self::make(
            sprintf('AI provider "%s" is temporarily skipped after repeated failures.', $provider),
            self::CATEGORY_BREAKER_OPEN,
            $provider,
        );
    }
}
