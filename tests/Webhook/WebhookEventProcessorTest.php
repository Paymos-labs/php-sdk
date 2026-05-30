<?php

declare(strict_types=1);

use Paymos\Exception\DuplicateEventException;
use Paymos\Webhook\InMemoryEventStore;
use Paymos\Webhook\WebhookEventProcessor;
use Paymos\Webhook\WebhookVerifier;

function test_webhook_event_processor_verifies_decodes_and_deduplicates_event()
{
    $secret = 'whsec_test_secret';
    $timestamp = 1739281200;
    $body = '{"event_id":"evt_123","type":"invoice.paid"}';
    $signature = hash_hmac('sha256', (string) $timestamp . '.' . $body, $secret);
    $header = 't=' . $timestamp . ',v1=' . $signature;
    $processor = new WebhookEventProcessor(
        new WebhookVerifier($secret, 300),
        new InMemoryEventStore(static function () use ($timestamp) {
            return $timestamp;
        })
    );

    $event = $processor->process($header, $body, $timestamp);
    assertSameValue('evt_123', $event['event_id'], 'Webhook event processor must return decoded event.');

    try {
        $processor->process($header, $body, $timestamp);
    } catch (DuplicateEventException $e) {
        assertTrueValue(true, 'Duplicate event must throw DuplicateEventException.');
        return;
    }

    throw new RuntimeException('Duplicate webhook event did not throw DuplicateEventException.');
}
