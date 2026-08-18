<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Contracts;

use App\Modules\Notifications\Support\NotificationEnvelope;

/**
 * A delivery transport (spec 22.1).
 *
 * Behind a contract so a channel can be unavailable without the dispatcher
 * knowing why. On Hostinger, Telegram is reachable and SMTP frequently is not,
 * and which one works is a deployment fact rather than a code fact.
 */
interface NotificationChannel
{
    public function key(): string;

    /** Whether this channel is configured well enough to attempt a send. */
    public function isAvailable(): bool;

    /**
     * @param  array<string, mixed>  $recipient
     * @return array{sent: bool, reason: string|null, reference: string|null}
     */
    public function send(array $recipient, NotificationEnvelope $envelope): array;
}
