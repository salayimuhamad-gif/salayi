<?php

declare(strict_types=1);

namespace App\Modules\Leads\Services;

use RuntimeException;

/**
 * Thrown inside {@see PhoneRevealService::reveal()} when a subject has a
 * stored ciphertext that will not decrypt.
 *
 * It exists purely to unwind the transaction. By the time decryption is
 * attempted, the ledger row and the audit record are already written in
 * this transaction — and a reveal that produced no number must not leave
 * evidence claiming one was released. Throwing rolls both back.
 *
 * It carries NO message and NO context on purpose. Every other failure in
 * that method is caught as a generic Throwable and reported by class name
 * only, for the same reason: an exception message here could carry a bound
 * query parameter, and a bound parameter in this class could be a phone
 * number. Distinguishing this case by TYPE rather than by text keeps that
 * rule intact while still letting the caller answer `no_phone_on_record`
 * instead of a misleading `unavailable`.
 */
final class UndecryptablePhone extends RuntimeException {}
