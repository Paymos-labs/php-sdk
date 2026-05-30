<?php

declare(strict_types=1);

namespace Paymos\Webhook;

use Paymos\Exception\DuplicateEventException;
use Paymos\Exception\SignatureMismatchException;
use Paymos\Exception\TimestampSkewException;

final class MultiEnvironmentWebhookVerifier
{
    /** @var array<string, string> */
    private $secrets;

    /** @var EventStoreInterface */
    private $eventStore;

    /** @var int */
    private $toleranceSeconds;

    /** @var int */
    private $dedupTtlSeconds;

    /**
     * @param array<string, string> $secrets
     */
    public function __construct(array $secrets, EventStoreInterface $eventStore, $toleranceSeconds = 300, $dedupTtlSeconds = 604800)
    {
        $normalized = array();
        foreach ($secrets as $environment => $secret) {
            if (!is_string($secret) || trim($secret) === '') {
                continue;
            }

            $normalized[(string) $environment] = $secret;
        }

        if (count($normalized) === 0) {
            throw new \InvalidArgumentException('At least one webhook secret is required.');
        }

        $this->secrets = $normalized;
        $this->eventStore = $eventStore;
        $this->toleranceSeconds = (int) $toleranceSeconds;
        $this->dedupTtlSeconds = (int) $dedupTtlSeconds;
    }

    public function process($signatureHeader, $rawBody, $now = null)
    {
        $lastTimestampSkew = null;
        $matches = array();

        foreach ($this->secrets as $environment => $secret) {
            try {
                $event = (new WebhookVerifier($secret, $this->toleranceSeconds))
                    ->decodeVerifiedPayload($signatureHeader, $rawBody, $now);
                $matches[] = new VerifiedWebhookEvent($environment, new WebhookEvent($event));
            } catch (TimestampSkewException $e) {
                $lastTimestampSkew = $e;
            } catch (SignatureMismatchException $e) {
                continue;
            }
        }

        if (count($matches) > 1) {
            throw new SignatureMismatchException('Webhook signature matched multiple configured environments.');
        }

        if (count($matches) === 1) {
            $match = $matches[0];
            if ($match->event()->id() === '') {
                throw new \RuntimeException('Webhook payload is missing event_id.');
            }

            if (!$this->eventStore->remember($match->event()->id(), $this->dedupTtlSeconds)) {
                throw new DuplicateEventException('Webhook event has already been processed: ' . $match->event()->id());
            }

            return $match;
        }

        if ($lastTimestampSkew !== null) {
            throw $lastTimestampSkew;
        }

        throw new SignatureMismatchException('Webhook signature does not match any configured environment.');
    }
}
