<?php

declare(strict_types=1);

namespace App\Modules\Projects\Enums;

/** Handover progress (spec 12.1), independent of construction status. */
enum DeliveryStatus: string
{
    case NotStarted = 'not_started';
    case Scheduled = 'scheduled';
    case PartiallyDelivered = 'partially_delivered';
    case Delivered = 'delivered';
    case Delayed = 'delayed';
    case Indefinite = 'indefinite';

    public function isAdverse(): bool
    {
        return in_array($this, [self::Delayed, self::Indefinite], true);
    }

    public function label(): string
    {
        return __('projects.delivery_statuses.'.$this->value);
    }
}
