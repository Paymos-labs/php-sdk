<?php

declare(strict_types=1);

namespace Paymos\Webhook;

final class InMemoryEventStore implements EventStoreInterface
{
    /** @var array<string, int> */
    private $expiresAt = array();

    /** @var callable */
    private $clock;

    public function __construct(callable $clock = null)
    {
        $this->clock = $clock ?: static function () {
            return time();
        };
    }

    public function remember($eventId, $ttlSeconds)
    {
        $now = (int) call_user_func($this->clock);
        $this->purgeExpired($now);
        $eventId = (string) $eventId;

        if (isset($this->expiresAt[$eventId])) {
            return false;
        }

        $this->expiresAt[$eventId] = $now + max(1, (int) $ttlSeconds);

        return true;
    }

    private function purgeExpired($now)
    {
        foreach ($this->expiresAt as $eventId => $expiresAt) {
            if ($expiresAt <= $now) {
                unset($this->expiresAt[$eventId]);
            }
        }
    }
}
