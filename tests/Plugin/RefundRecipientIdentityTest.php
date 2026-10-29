<?php

declare(strict_types=1);

use Paymos\Plugin\RefundRecipientIdentity;
use Paymos\Plugin\VerifiedRefund;

function ton_refund_recipient_fixture(string $recipient, string $network = 'TON', bool $isTest = false): VerifiedRefund
{
    $refund = array(
        'withdrawal_id' => 'wdr_1', 'invoice_id' => 'inv_1', 'project_id' => 'prj_1',
        'is_test' => $isTest, 'mode' => 'full', 'external_order_id' => 'order_1',
        'status' => 'completed', 'amount' => '1', 'currency' => 'USDT',
        'network' => $network, 'first_sender' => $recipient, 'destination_address' => $recipient,
    );
    return VerifiedRefund::fromApi($refund,
        array('invoice_id' => 'inv_1', 'project_id' => 'prj_1', 'is_test' => $isTest),
        new DateTimeImmutable('2026-10-29T00:00:00Z'));
}

function test_refund_recipient_ton_identity_accepts_official_raw_and_friendly_vectors()
{
    // Official vector also pinned by TonAddressOfficialVectorTests, not calculated by this comparator.
    $raw = '0:ca6e321c7cce9ecedf0a8ca2492ec8592494aa5fb5ce0387dff96ef6af982a3e';
    $uq = 'UQDKbjIcfM6ezt8KjKJJLshZJJSqX7XOA4ff-W72r5gqPuwA';
    $eq = 'EQDKbjIcfM6ezt8KjKJJLshZJJSqX7XOA4ff-W72r5gqPrHF';
    $test = '0QDKbjIcfM6ezt8KjKJJLshZJJSqX7XOA4ff-W72r5gqPleK';
    $rawLive = ton_refund_recipient_fixture($raw);
    assertTrueValue(RefundRecipientIdentity::same($rawLive, ton_refund_recipient_fixture($uq)), 'Raw and UQ refer to one TON account.');
    assertTrueValue(RefundRecipientIdentity::same(ton_refund_recipient_fixture($eq), $rawLive), 'Bounce flag is not account identity.');
    assertTrueValue(RefundRecipientIdentity::same($rawLive, ton_refund_recipient_fixture(str_replace('-', '+', $uq))), 'Standard Base64 is accepted too.');
    assertTrueValue(RefundRecipientIdentity::same(ton_refund_recipient_fixture($raw, 'TON', true), ton_refund_recipient_fixture($test, 'TON', true)), 'Sandbox may compare a legacy raw pin to test-only output.');
    assertTrueValue(RefundRecipientIdentity::same(ton_refund_recipient_fixture($raw, 'TON_TESTNET'), ton_refund_recipient_fixture($test, 'TON_TESTNET')), 'The physical testnet accepts test-only addresses.');
    $masterRaw = '-1:e56754f83426f69b09267bd876ac97c44821345b7e266bd956a7bfbfb98df35c';
    $masterUrl = 'Ef_lZ1T4NCb2mwkme9h2rJfESCE0W34ma9lWp7-_uY3zXDvq';
    $masterStandard = 'Ef/lZ1T4NCb2mwkme9h2rJfESCE0W34ma9lWp7+/uY3zXDvq';
    assertTrueValue(RefundRecipientIdentity::same(ton_refund_recipient_fixture($masterRaw), ton_refund_recipient_fixture($masterUrl)), 'TEP-2 signed masterchain vector is valid.');
    assertTrueValue(RefundRecipientIdentity::same(ton_refund_recipient_fixture($masterUrl), ton_refund_recipient_fixture($masterStandard)), 'Both complete alphabets identify the same account.');
}

function test_refund_recipient_ton_identity_fails_closed_without_weakening_other_networks()
{
    $raw = '0:ca6e321c7cce9ecedf0a8ca2492ec8592494aa5fb5ce0387dff96ef6af982a3e';
    $uq = 'UQDKbjIcfM6ezt8KjKJJLshZJJSqX7XOA4ff-W72r5gqPuwA';
    $test = '0QDKbjIcfM6ezt8KjKJJLshZJJSqX7XOA4ff-W72r5gqPleK';
    $pin = ton_refund_recipient_fixture($raw);
    foreach (array(
        ton_refund_recipient_fixture($test),
        ton_refund_recipient_fixture(substr($uq, 0, 47) . 'B'),
        ton_refund_recipient_fixture('0:' . str_repeat('0', 64)),
        ton_refund_recipient_fixture($uq, 'TON_TESTNET'),
        ton_refund_recipient_fixture($uq, 'TON', true),
        ton_refund_recipient_fixture('0:' . str_repeat('g', 64)),
        ton_refund_recipient_fixture('-0:' . substr($raw, 2)),
        ton_refund_recipient_fixture('+0:' . substr($raw, 2)),
        ton_refund_recipient_fixture('00:' . substr($raw, 2)),
        ton_refund_recipient_fixture('128:' . substr($raw, 2)),
        ton_refund_recipient_fixture('Pubjns2gp7DGCnEH7EOWeCnb6Lw1akm538YYaz6sdLVHfRB2'),
        ton_refund_recipient_fixture('Ef/lZ1T4NCb2mwkme9h2rJfESCE0W34ma9lWp7-_uY3zXDvq'),
        ton_refund_recipient_fixture('not-an-address'),
    ) as $other) {
        assertFalseValue(RefundRecipientIdentity::same($pin, $other), 'Wrong identity, invalid address, network or environment must fail closed.');
    }
    assertFalseValue(RefundRecipientIdentity::same(ton_refund_recipient_fixture($test), ton_refund_recipient_fixture($test)), 'An identical test-only address is not allowed on live TON mainnet.');
    assertTrueValue(RefundRecipientIdentity::same(ton_refund_recipient_fixture('TAddress', 'TRC20'), ton_refund_recipient_fixture('TAddress', 'TRC20')), 'Other networks retain exact comparison.');
    assertFalseValue(RefundRecipientIdentity::same(ton_refund_recipient_fixture('TAddress', 'TRC20'), ton_refund_recipient_fixture('taddress', 'TRC20')), 'Other-network casing does not become equivalent.');
}
