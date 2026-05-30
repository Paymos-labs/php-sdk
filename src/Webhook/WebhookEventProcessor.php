<?php

declare(strict_types=1);

namespace Paymos\Webhook;

use Paymos\Exception\DuplicateEventException;

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
        $this->verifier = $verifier;
        $this->eventStore = $eventStore;
        $this->dedupTtlSeconds = (int) $dedupTtlSeconds;
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
            throw new DuplicateEventException('Webhook event has already been processed: ' . $event['event_id']);
        }

        return $event;
    }
}
