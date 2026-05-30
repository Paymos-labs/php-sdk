<?php

declare(strict_types=1);

namespace Paymos\Webhook;

interface EventStoreInterface
{
    /**
     * @param string $eventId
     * @param int $ttlSeconds
     * @return bool True when the event was stored, false when it was already seen.
     */
    public function remember($eventId, $ttlSeconds);
}
