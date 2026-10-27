<?php

declare(strict_types=1);

namespace SystemDesignLab\Foundation;

/**
 * A virtual clock for simulations.
 *
 * Chapters take an explicit `nowMs` instead of reading the wall clock, so a
 * retry, a lease expiry or a quiet-hours boundary is a deterministic event,
 * not a race with the machine.
 */
interface Clock
{
    public function nowMs(): int;
}
