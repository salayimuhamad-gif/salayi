<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use RuntimeException;

/**
 * Raised INSIDE the profile phone-change transaction when the pre-check
 * finds the number on another account (v5 correction).
 *
 * It exists so the refusal can be AUDITED OUTSIDE that transaction. The
 * v4 shape wrote the `pre_check` security record inside
 * `applyPhoneChange()` — inside the very transaction the refusal then
 * aborted — so the rollback erased the evidence, while the sibling
 * `unique_constraint` path (whose comment promised "the refusal survives
 * it") audited correctly after the rollback. Both paths now share that
 * survival: the transaction dies, THEN the refusal is recorded, then the
 * caller-facing ValidationException is raised.
 *
 * Deliberately NOT a ValidationException itself: `toE164()`'s format
 * refusals also raise ValidationException, and a catch keyed on that
 * type would audit a typo as a conflict.
 */
final class ProfilePhoneConflict extends RuntimeException {}
