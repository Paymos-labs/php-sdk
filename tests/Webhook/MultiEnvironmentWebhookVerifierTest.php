<?php

declare(strict_types=1);

use Paymos\Exception\DuplicateEventException;
use Paymos\Exception\SignatureMismatchException;
use Paymos\Webhook\EventStoreInterface;
use Paymos\Webhook\MultiEnvironmentWebhookVerifier;

final class SdkFakeEventStore implements EventStoreInterface
{
    /** @var array<string, bool> */
    private $events = array();

    public function remember($eventId, $ttlSeconds)
    {
        $key = (string) $eventId;
        if (isset($this->events[$key])) {
            return false;
        }

        $this->events[$key] = true;
        return true;
    }
}

function sdk_signed_header($secret, $body, $timestamp = 1709000000)
{
    return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
}

function test_multi_environment_webhook_verifier_returns_matching_environment_and_event_object()
{
    $body = json_encode(array(
        'event_id' => 'evt_sandbox',
        'event_type' => 'invoice.paid',
        'data' => array('is_test' => true),
    ));
    $verifier = new MultiEnvironmentWebhookVerifier(array(
        'sandbox' => 'whsec_test',
        'live' => 'whsec_live',
    ), new SdkFakeEventStore());

    $verified = $verifier->process(sdk_signed_header('whsec_test', $body), $body, 1709000000);

    assertSameValue('sandbox', $verified->environment(), 'matching sandbox secret must select sandbox environment.');
    assertSameValue('evt_sandbox', $verified->event()->id(), 'verified webhook event must be exposed as WebhookEvent.');
}

function test_multi_environment_webhook_verifier_rejects_unknown_secret()
{
    $body = json_encode(array('event_id' => 'evt_bad', 'event_type' => 'invoice.paid'));
    $verifier = new MultiEnvironmentWebhookVerifier(array('live' => 'whsec_live'), new SdkFakeEventStore());

    try {
        $verifier->process(sdk_signed_header('whsec_other', $body), $body, 1709000000);
    } catch (SignatureMismatchException $e) {
        assertTrueValue(true, 'unknown secret must throw SignatureMismatchException.');
        return;
    }

    throw new RuntimeException('unknown secret must throw SignatureMismatchException.');
}

function test_multi_environment_webhook_verifier_rejects_signature_matching_multiple_environments()
{
    $body = json_encode(array('event_id' => 'evt_ambiguous', 'event_type' => 'invoice.paid'));
    $verifier = new MultiEnvironmentWebhookVerifier(array(
        'sandbox' => 'whsec_same',
        'live' => 'whsec_same',
    ), new SdkFakeEventStore());

    try {
        $verifier->process(sdk_signed_header('whsec_same', $body), $body, 1709000000);
    } catch (SignatureMismatchException $e) {
        assertTrueValue(true, 'ambiguous environment match must be rejected.');
        return;
    }

    throw new RuntimeException('ambiguous environment match must throw SignatureMismatchException.');
}

function test_multi_environment_webhook_verifier_deduplicates_after_signature_match()
{
    $body = json_encode(array('event_id' => 'evt_duplicate', 'event_type' => 'invoice.paid'));
    $store = new SdkFakeEventStore();
    $verifier = new MultiEnvironmentWebhookVerifier(array('live' => 'whsec_live'), $store);
    $signature = sdk_signed_header('whsec_live', $body);

    $verifier->process($signature, $body, 1709000000);

    try {
        $verifier->process($signature, $body, 1709000000);
    } catch (DuplicateEventException $e) {
        assertTrueValue(true, 'duplicate event id must throw DuplicateEventException.');
        return;
    }

    throw new RuntimeException('duplicate event id must throw DuplicateEventException.');
}
