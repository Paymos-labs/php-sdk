<?php

declare(strict_types=1);

use Paymos\Webhook\WebhookVerifier;

function test_webhook_verifier_accepts_valid_signature()
{
    $secret = 'whsec_test_secret';
    $timestamp = 1739281200;
    $body = '{"event_id":"evt_123","type":"invoice.paid"}';
    $signature = hash_hmac('sha256', (string) $timestamp . '.' . $body, $secret);
    $header = 't=' . $timestamp . ',v1=' . $signature;

    $verifier = new WebhookVerifier($secret, 300);

    assertTrueValue(
        $verifier->verify($header, $body, $timestamp),
        'Webhook verifier must accept a valid t/v1 signature.'
    );
}

function test_webhook_verifier_accepts_any_matching_rotation_signature()
{
    $secret = 'whsec_test_secret';
    $timestamp = 1739281200;
    $body = '{"event_id":"evt_123","type":"invoice.paid"}';
    $valid = hash_hmac('sha256', (string) $timestamp . '.' . $body, $secret);
    $header = 't=' . $timestamp . ',v1=invalid,v1=' . $valid;

    $verifier = new WebhookVerifier($secret, 300);

    assertTrueValue(
        $verifier->verify($header, $body, $timestamp),
        'Webhook verifier must allow multiple v1 signatures during secret rotation.'
    );
}

function test_webhook_verifier_rejects_stale_timestamp()
{
    $secret = 'whsec_test_secret';
    $timestamp = 1739281200;
    $body = '{"event_id":"evt_123","type":"invoice.paid"}';
    $signature = hash_hmac('sha256', (string) $timestamp . '.' . $body, $secret);
    $header = 't=' . $timestamp . ',v1=' . $signature;

    $verifier = new WebhookVerifier($secret, 300);

    assertFalseValue(
        $verifier->verify($header, $body, $timestamp + 301),
        'Webhook verifier must reject timestamps outside tolerance.'
    );
}

function test_webhook_verifier_rejects_bad_signature()
{
    $verifier = new WebhookVerifier('whsec_test_secret', 300);

    assertFalseValue(
        $verifier->verify('t=1739281200,v1=bad', '{"event_id":"evt_123"}', 1739281200),
        'Webhook verifier must reject non-matching signatures.'
    );
}

function test_webhook_verifier_rejects_duplicate_timestamps()
{
    $secret = 'whsec_test_secret';
    $timestamp = 1739281200;
    $body = '{"event_id":"evt_123"}';
    $signature = hash_hmac('sha256', (string) $timestamp . '.' . $body, $secret);
    $verifier = new WebhookVerifier($secret, 300);

    assertFalseValue(
        $verifier->verify('t=' . $timestamp . ',t=' . $timestamp . ',v1=' . $signature, $body, $timestamp),
        'Webhook verifier must reject an ambiguous timestamp header.'
    );
}

function test_webhook_verifier_validates_verified_event_envelope()
{
    $secret = 'whsec_test_secret';
    $timestamp = 1739281200;
    $body = '{"event_id":"evt_123","event_type":"invoice.paid","data":{}}';
    $signature = hash_hmac('sha256', (string) $timestamp . '.' . $body, $secret);
    $verifier = new WebhookVerifier($secret, 300);

    try {
        $verifier->decodeVerifiedPayload('t=' . $timestamp . ',v1=' . $signature, $body, $timestamp);
    } catch (\RuntimeException $error) {
        assertTrueValue(strpos($error->getMessage(), 'version') !== false, 'Missing version must be rejected.');
        return;
    }

    throw new \RuntimeException('Malformed verified webhook envelope was accepted.');
}

/**
 * All three payment_channel.deposit.* events carry the full deposit wire shape
 * as `data` (PaymentChannelDepositWireContract — the same array
 * paymentChannelDeposits() already returns), so a `confirmed` body must survive
 * decodeVerifiedPayload() unchanged: money stays a string, is_final stays true,
 * and absent optionals stay absent. This is a native-TRX deposit — no contract,
 * no attributable payer address, no explorer link minted yet — so
 * contract_address, source_address, explorer_url and
 * first_included_block_timestamp are OMITTED from the JSON below, never sent as
 * null; they must still be missing after decoding, not merely null.
 */
function test_webhook_verifier_round_trips_a_payment_channel_deposit_confirmed_event()
{
    $secret = 'whsec_test_secret';
    $timestamp = 1700000000;
    $body = json_encode(array(
        'event_id' => 'evt_2',
        'event_type' => 'payment_channel.deposit.confirmed',
        'version' => 1,
        'occurred_at' => $timestamp,
        'data' => array(
            'id' => 'pcd_9',
            'payment_channel_id' => 'pc_1',
            'project_id' => 'prj_1',
            'payment_channel_external_id' => 'customer-42',
            'status' => 'confirmed',
            'is_final' => true,
            'is_test' => false,
            'currency' => 'TRX',
            'network' => 'TRC20',
            'chain_id' => 728126428,
            'gross' => '100.00',
            'fee' => '1.00',
            'net' => '99.00',
            'applied_fee_percent' => 1,
            'customer_fee_percent' => 0,
            'tx_hash' => '9a2f1c',
            'transfer_id' => 'trf_1',
            'destination_address' => 'TChannelAddress',
            'block_height' => 60000000,
            'created_at' => 1700000000,
            'updated_at' => 1700000100,
            'confirmed_at' => 1700000100,
        ),
    ));
    $signature = hash_hmac('sha256', (string) $timestamp . '.' . $body, $secret);
    $header = 't=' . $timestamp . ',v1=' . $signature;

    $verifier = new WebhookVerifier($secret, 300);
    $event = $verifier->decodeVerifiedPayload($header, $body, $timestamp);

    assertSameValue(
        'payment_channel.deposit.confirmed',
        $event['event_type'],
        'event type must round-trip unchanged.'
    );

    $deposit = $event['data'];
    assertTrueValue(is_string($deposit['gross']), 'gross must decode as a string, never a float.');
    assertTrueValue(is_string($deposit['fee']), 'fee must decode as a string, never a float.');
    assertTrueValue(is_string($deposit['net']), 'net must decode as a string, never a float.');
    assertSameValue('100.00', $deposit['gross'], 'gross must keep its exact decimal value.');
    assertSameValue('1.00', $deposit['fee'], 'fee must keep its exact decimal value.');
    assertSameValue('99.00', $deposit['net'], 'net must keep its exact decimal value.');
    assertTrueValue($deposit['is_final'], 'is_final must round-trip true for a settled deposit.');

    $absentOptionals = array(
        'contract_address', 'source_address', 'explorer_url', 'first_included_block_timestamp',
    );
    foreach ($absentOptionals as $optional) {
        assertFalseValue(
            array_key_exists($optional, $deposit),
            'absent optional "' . $optional . '" must stay ABSENT after decoding, not merely null.'
        );
    }
}

