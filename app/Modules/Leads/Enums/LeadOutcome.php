<?php

declare(strict_types=1);

namespace App\Modules\Leads\Enums;

/**
 * How a sales contact ended (File one §9 Leads 2).
 *
 * A closed set rather than free text, because these are reported on. "Called,
 * no answer" and "called, not interested" move a lead in completely different
 * directions — one is a retry, the other is a close — and free text makes the
 * difference unqueryable within a week of two people typing it differently.
 *
 * The values are frozen once stored: they are persisted on `lead_tasks.outcome`
 * and renaming one would silently reclassify history.
 */
enum LeadOutcome: string
{
    case Reached = 'reached';
    case NoAnswer = 'no_answer';
    case WrongNumber = 'wrong_number';
    case CallbackRequested = 'callback_requested';
    case NotInterested = 'not_interested';
    case NotQualified = 'not_qualified';
    case Viewing = 'viewing_scheduled';
    case OfferMade = 'offer_made';
    case Won = 'won';
    case Lost = 'lost';

    /**
     * Whether this outcome ends the pursuit.
     *
     * Used to stop follow-up tasks being generated for a lead that has said no.
     * Continuing to chase somebody who declined is both a poor experience and,
     * where consent was the basis for contacting them at all, a compliance
     * problem.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::NotInterested, self::NotQualified, self::WrongNumber, self::Won, self::Lost => true,
            default => false,
        };
    }

    /** Whether the person asked to be contacted again — the opposite of terminal. */
    public function invitesFollowUp(): bool
    {
        return match ($this) {
            self::CallbackRequested, self::NoAnswer, self::Viewing, self::OfferMade => true,
            default => false,
        };
    }

    public function label(): string
    {
        return __('leads.outcomes.'.$this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $o): string => $o->value, self::cases());
    }
}
