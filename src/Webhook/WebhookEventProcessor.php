<?php

declare(strict_types=1);

namespace Paymos\Webhook;

use Paymos\Exception\DuplicateEventException;
use Paymos\Exception\EventInProgressException;

final class WebhookEventProcessor
{
    /** @var WebhookVerifier */
    private $verifier;

    /** @var EventStoreInterface */
    private $eventStore;

    /** @var int */
    private $dedupTtlSeconds;

    public function __construct(WebhookVerifier $verifier, EventStoreInterface $eventStore, $dedupTtlSeconds = 604800)
    {
        if (!is_int($dedupTtlSeconds) || $dedupTtlSeconds < 1) {
            throw new \InvalidArgumentException('Webhook deduplication TTL must be a positive integer.');
        }

        $this->verifier = $verifier;
        $this->eventStore = $eventStore;
        $this->dedupTtlSeconds = $dedupTtlSeconds;
    }

    /**
     * @return array
     */
    public function process($signatureHeader, $rawBody, $now = null)
    {
        $event = $this->verifier->decodeVerifiedPayload($signatureHeader, $rawBody, $now);
        if (!isset($event['event_id']) || !is_string($event['event_id']) || $event['event_id'] === '') {
            throw new \RuntimeException('Webhook payload is missing event_id.');
        }

        if (!$this->eventStore->remember($event['event_id'], $this->dedupTtlSeconds)) {
            self::throwSeen($this->eventStore, $event['event_id']);
        }

        return $event;
    }

    /**
     * remember() said "seen". A store that can tell is asked whether the event
     * was committed; one still locked by an unfinished delivery is not a
     * duplicate and must not be acknowledged (see EventInProgressException).
     *
     * @param string $eventId
     */
    private static function throwSeen(EventStoreInterface $store, $eventId)
    {
        if ($store instanceof CommitAwareEventStoreInterface && !$store->isCommitted($eventId)) {
            throw new EventInProgressException('Webhook event is still being processed by another delivery: ' . $eventId);
        }

        throw new DuplicateEventException('Webhook event has already been processed: ' . $eventId);
    }
}
