<?php

declare(strict_types=1);

namespace App\Modules\Leads\Enums;

/**
 * Why an agent needed a phone number (File one §9 Leads.3).
 *
 * A closed list, so reveals can be reported on. "Why did your team access 400
 * numbers last month" is answerable with these and unanswerable with free text.
 */
enum RevealReason: string
{
    /** The person asked to be called. The strongest justification there is. */
    case CallbackRequested = 'callback_requested';

    /** Following up an enquiry the person started. */
    case EnquiryFollowUp = 'enquiry_follow_up';

    /** A viewing is being arranged at the person's request. */
    case ViewingArrangement = 'viewing_arrangement';

    /** An existing customer relationship. */
    case ExistingCustomer = 'existing_customer';

    /** Anything else — requires a written note. */
    case Other = 'other';

    /**
     * Whether this reason obliges the agent to explain themselves in words.
     *
     * `other` is the escape hatch, and an escape hatch with no friction becomes
     * the default. Requiring a note makes it the deliberate choice it should be.
     */
    public function requiresNote(): bool
    {
        return $this === self::Other;
    }

    /**
     * Whether the subject initiated contact.
     *
     * Reveals where they did not are the ones worth reviewing: nothing here is
     * forbidden, but an agent whose reveals are overwhelmingly agent-initiated
     * is doing something different from one following up callbacks.
     */
    public function isSubjectInitiated(): bool
    {
        return match ($this) {
            self::CallbackRequested, self::EnquiryFollowUp, self::ViewingArrangement => true,
            default => false,
        };
    }

    public function label(): string
    {
        return __('leads.reveal_reasons.'.$this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $r): string => $r->value, self::cases());
    }
}
