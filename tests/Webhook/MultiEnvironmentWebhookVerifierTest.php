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

function sdk_webhook_body($eventId, array $data = array())
{
    return json_encode(array(
        'event_id' => $eventId,
        'event_type' => 'invoice.paid',
        'version' => 1,
        'occurred_at' => 1709000000,
        'data' => $data,
    ));
}

function test_multi_environment_webhook_verifier_returns_matching_environment_and_event_object()
{
    $body = sdk_webhook_body('evt_sandbox', array('is_test' => true));
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
    $body = sdk_webhook_body('evt_bad');
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
    $body = sdk_webhook_body('evt_ambiguous');
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
    $body = sdk_webhook_body('evt_duplicate');
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

final class SdkFakeCommitAwareEventStore implements Paymos\Webhook\CommitAwareEventStoreInterface
{
    /** @var array<string, bool> event id => committed */
    public $events = array();

    public function remember($eventId, $ttlSeconds)
    {
        $key = (string) $eventId;
        if (array_key_exists($key, $this->events)) {
            return false;
        }

        $this->events[$key] = false;
        return true;
    }

    public function isCommitted($eventId)
    {
        return !empty($this->events[(string) $eventId]);
    }
}

function test_multi_environment_webhook_verifier_reports_an_event_still_in_flight()
{
    // BUG-103: attempt 1 holds the lock while its reverse-verify GET is slow;
    // the server times out after 10 s and retries. Answering the retry 200
    // "duplicate" marks the event delivered — and if attempt 1 then fails, it
    // is lost. An event that is only locked, not committed, must be reported as
    // in progress so the plugin can answer non-2xx.
    $body = sdk_webhook_body('evt_slow', array('is_test' => true));
    $store = new SdkFakeCommitAwareEventStore();
    $store->events['evt_slow'] = false;
    $verifier = new MultiEnvironmentWebhookVerifier(array('sandbox' => 'whsec_test'), $store);

    try {
        $verifier->process(sdk_signed_header('whsec_test', $body), $body, 1709000000);
        throw new RuntimeException('an in-flight event must not verify as new.');
    } catch (Paymos\Exception\EventInProgressException $e) {
        assertTrueValue(true, 'in-flight event reported as in progress.');
    }
}

function test_multi_environment_webhook_verifier_still_reports_a_committed_event_as_duplicate()
{
    $body = sdk_webhook_body('evt_done', array('is_test' => true));
    $store = new SdkFakeCommitAwareEventStore();
    $store->events['evt_done'] = true;
    $verifier = new MultiEnvironmentWebhookVerifier(array('sandbox' => 'whsec_test'), $store);

    try {
        $verifier->process(sdk_signed_header('whsec_test', $body), $body, 1709000000);
        throw new RuntimeException('a committed event must not verify as new.');
    } catch (DuplicateEventException $e) {
        assertTrueValue(true, 'committed event reported as duplicate.');
    }
}
