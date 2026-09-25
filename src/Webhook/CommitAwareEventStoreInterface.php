<?php

declare(strict_types=1);

namespace Paymos\Webhook;

/**
 * An event store that can tell a processed event from one still in flight.
 *
 * remember() returns false in both cases. A store implementing this interface
 * lets the verifier answer them differently: a committed event is a duplicate
 * (2xx), an event only locked by an unfinished delivery is not
 * (EventInProgressException, non-2xx) — so a retry that arrives while the first
 * attempt is still running cannot mark the event delivered.
 */
interface CommitAwareEventStoreInterface extends EventStoreInterface
{
    /**
     * @param string $eventId
     * @return bool True only when the event was processed and committed; false
     *              while it is merely locked, or when it is not there at all.
     */
    public function isCommitted($eventId);
}
