<?php

declare(strict_types=1);

use Paymos\Plugin\ExternalRefundObservation;
use Paymos\Plugin\VerifiedRefund;

function external_cash_fixture(array $changes = array()): VerifiedRefund
{
    $wire = array_replace(array('withdrawal_id' => 'wdr_external', 'invoice_id' => 'inv_paid', 'project_id' => 'prj_cms',
        'is_test' => true, 'mode' => 'overpayment', 'external_order_id' => 'dashboard-key', 'status' => 'completed',
        'amount' => '20', 'currency' => 'USDT', 'network' => 'Tron', 'first_sender' => 'sender-A'), $changes);
    return VerifiedRefund::fromApi($wire, array('invoice_id' => 'inv_paid', 'project_id' => 'prj_cms', 'is_test' => true), new DateTimeImmutable('2026-10-28T00:00:00Z'));
}

function test_external_cash_observation_records_cash_without_inventing_a_native_draft()
{
    $row = ExternalRefundObservation::fold(null, external_cash_fixture(), 'order:42');
    assertSameValue(null, $row['review_reason'], 'Completed excess can be observed without a financial refund.');
    assertTrueValue(ExternalRefundObservation::needsNote($row), 'One initial cash note is needed.');
    $full = ExternalRefundObservation::fold(null, external_cash_fixture(array('mode' => 'full', 'amount' => '120')), 'order:42');
    assertSameValue('refund_unbound_full_draft_missing', $full['review_reason'], 'Full cash cannot fabricate a native full draft.');
    assertFalseValue(ExternalRefundObservation::needsNote($full), 'Full remains explicit review.');
}

function test_external_cash_observation_reorg_and_recompletion_preserve_the_one_note_marker()
{
    $row = ExternalRefundObservation::fold(null, external_cash_fixture(), 'order:42');
    $row['note_id'] = 'native-note-7';
    $row = ExternalRefundObservation::fold($row, external_cash_fixture(array('status' => 'signed')), 'order:42');
    assertSameValue('cash_rewound', $row['review_reason'], 'A previous native note does not prove current completion.');
    $row = ExternalRefundObservation::fold($row, external_cash_fixture(), 'order:42');
    assertSameValue('native-note-7', $row['note_id'], 'Recompletion retains the exact previous note.');
    assertFalseValue(ExternalRefundObservation::needsNote($row), 'No second note is emitted.');
}

function test_external_cash_observation_rejects_a_changed_pin_or_payment_mapping()
{
    $old = ExternalRefundObservation::fold(null, external_cash_fixture(), 'order:42');
    foreach (array(array('amount' => '21'), array('mode' => 'full'), array('first_sender' => 'sender-B'), array('external_order_id' => 'another-key')) as $change) {
        try { ExternalRefundObservation::fold($old, external_cash_fixture($change), 'order:42'); }
        catch (RuntimeException $error) { assertSameValue('refund_unbound_evidence_conflict', $error->getMessage(), 'Immutable evidence must not retarget cash.'); continue; }
        throw new RuntimeException('Expected immutable cash pin refusal.');
    }
    try { ExternalRefundObservation::fold($old, external_cash_fixture(), 'order:99'); }
    catch (RuntimeException $error) { return; }
    throw new RuntimeException('Expected payment mapping refusal.');
}

function test_external_cash_observation_corrupt_stored_rows_fail_closed()
{
    foreach (array(array(), array('payment_key' => 'order:42', 'observation' => null, 'note_id' => null, 'review_reason' => null)) as $row) {
        try { ExternalRefundObservation::stored($row); }
        catch (RuntimeException $error) { assertSameValue('refund_external_history_corrupt', $error->getMessage(), 'Malformed cash history is not silently treated as an available action.'); continue; }
        throw new RuntimeException('Expected corrupt stored observation refusal.');
    }
}

function test_external_cash_observation_ton_alias_preserves_mapping_and_note()
{
    $raw = '0:ca6e321c7cce9ecedf0a8ca2492ec8592494aa5fb5ce0387dff96ef6af982a3e';
    $friendly = '0QDKbjIcfM6ezt8KjKJJLshZJJSqX7XOA4ff-W72r5gqPleK';
    $row = ExternalRefundObservation::fold(null, external_cash_fixture(array('network' => 'TON', 'first_sender' => $raw)), 'order:42');
    $row['note_id'] = 'note-1';
    $updated = ExternalRefundObservation::fold($row, external_cash_fixture(array('network' => 'TON', 'first_sender' => $friendly)), 'order:42');
    assertSameValue('note-1', $updated['note_id'], 'Changing representation cannot create a second note.');
    assertSameValue($raw, $row['observation']['first_sender'], 'The previous observation remains an unchanged value.');
    assertFalseValue(ExternalRefundObservation::needsNote($updated), 'Existing note stays bound to the same account.');
    try {
        ExternalRefundObservation::fold($row, external_cash_fixture(array('network' => 'TON', 'first_sender' => '0:' . str_repeat('0', 64))), 'order:42');
    } catch (RuntimeException $error) {
        assertSameValue('refund_unbound_evidence_conflict', $error->getMessage(), 'A new account is rejected.');
        return;
    }
    throw new RuntimeException('Expected TON recipient mismatch refusal.');
}
