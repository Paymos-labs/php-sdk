<?php

declare(strict_types=1);

use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Http\HttpResponse;
use Paymos\Http\TransportInterface;
use Paymos\Plugin\EffectClaim;
use Paymos\Plugin\NativeProjectionResult;
use Paymos\Plugin\NativeRefundProjectorInterface;
use Paymos\Plugin\RefundCoordinator;
use Paymos\Plugin\RefundIntent;
use Paymos\Plugin\RefundIntentPage;
use Paymos\Plugin\RefundIntentStoreInterface;
use Paymos\Plugin\RefundStates;
use Paymos\Plugin\VerifiedRefund;

final class RefundMemoryDatabase
{
    public $rows = array();
    public $locked = false;
    public $transactionDepth = 0;
    public $bindingConflict = false;
}

final class RefundMemoryStore implements RefundIntentStoreInterface
{
    public $db;

    public function __construct(RefundMemoryDatabase $db) { $this->db = $db; }

    public function withPaymentLock(string $paymentKey, callable $action)
    {
        assertFalseValue($this->db->locked, 'Payment operations are serialized.');
        $this->db->locked = true;
        try { return $action(); } finally { $this->db->locked = false; }
    }

    public function transaction(callable $action)
    {
        assertTrueValue($this->db->locked, 'Transactions are inside the payment lock.');
        $before = $this->db->rows;
        $this->db->transactionDepth++;
        try { return $action(); } catch (Throwable $error) { $this->db->rows = $before; throw $error; }
        finally { $this->db->transactionDepth--; }
    }

    public function createIntent(RefundIntent $intent): RefundIntent
    {
        $data = $intent->toArray();
        $existing = $this->findByRequestToken($data['installation_id'], $data['request_token']);
        if ($existing !== null) {
            if ($existing->toArray()['payload_hash'] !== $data['payload_hash']) {
                throw new RuntimeException('refund_request_conflict');
            }
            return $existing;
        }
        foreach ($this->db->rows as $row) {
            if ($row->paymentKey() !== $intent->paymentKey()) { continue; }
            if (!in_array($row->cashState(), array('completed', 'failed', 'cancelled'), true)
                || ($row->mode() === 'full' && $row->cashState() === 'completed')
                || $row->projectionState() === 'native_review') {
                throw new RuntimeException('refund_in_flight');
            }
        }
        $this->db->rows[$intent->operationId()] = $intent;
        return $intent;
    }

    public function findByRequestToken(string $installationId, string $token): ?RefundIntent
    {
        foreach ($this->db->rows as $row) {
            $data = $row->toArray();
            if ($data['installation_id'] === $installationId && $data['request_token'] === $token) { return $row; }
        }
        return null;
    }

    public function getIntent(string $operationId): RefundIntent { return $this->db->rows[$operationId]; }

    public function findByExternalId(string $key): ?RefundIntent
    {
        foreach ($this->db->rows as $row) { if ($row->externalOrderId() === $key) { return $row; } }
        return null;
    }

    public function findByWithdrawalId(string $id): ?RefundIntent
    {
        foreach ($this->db->rows as $row) { if ($row->withdrawalId() === $id) { return $row; } }
        return null;
    }

    public function bindWithdrawal(string $operationId, string $id): string
    {
        if ($this->db->bindingConflict) { return 'conflict'; }
        $intent = $this->getIntent($operationId);
        $existing = $this->findByWithdrawalId($id);
        if (($existing !== null && $existing->operationId() !== $operationId)
            || ($intent->withdrawalId() !== null && $intent->withdrawalId() !== $id)) { return 'conflict'; }
        $this->replace($operationId, array('withdrawal_id' => $id));
        return $intent->withdrawalId() === null ? 'bound' : 'same';
    }

    public function recordObservation(string $operationId, VerifiedRefund $snapshot): void
    {
        assertSameValue($this->getIntent($operationId)->withdrawalId(), $snapshot->withdrawalId(), 'Observation is bound by ID.');
        $this->replace($operationId, array('observation' => $snapshot));
    }

    public function recordProjection(string $operationId, NativeProjectionResult $result): void
    {
        $this->replace($operationId, array('projection' => $result));
    }

    public function recordState(string $operationId, string $cashState, string $projectionState, ?string $reason): void
    {
        $this->replace($operationId, array('cash_state' => $cashState, 'projection_state' => $projectionState, 'review_reason' => $reason));
    }

    public function claimEffect(string $operationId, string $kind, int $generation): EffectClaim
    {
        $effects = $this->getIntent($operationId)->toArray()['effects'];
        $key = $kind . ':' . $generation;
        if (isset($effects[$key])) { return new EffectClaim(EffectClaim::ALREADY_STARTED); }
        $effects[$key] = RefundStates::EFFECT_STARTED;
        $this->replace($operationId, array('effects' => $effects));
        return new EffectClaim(EffectClaim::CLAIMED);
    }

    public function recordEffectOutcome(string $operationId, string $kind, int $generation, string $state): void
    {
        $effects = $this->getIntent($operationId)->toArray()['effects'];
        $effects[$kind . ':' . $generation] = $state;
        $this->replace($operationId, array('effects' => $effects));
    }

    public function listDue(DateTimeImmutable $now, int $limit, ?string $cursor): RefundIntentPage
    {
        return new RefundIntentPage(array_slice(array_values($this->db->rows), 0, $limit), null);
    }

    private function replace(string $operationId, array $values): void
    {
        $this->db->rows[$operationId] = new RefundIntent(array_replace($this->getIntent($operationId)->toArray(), $values));
    }
}

final class RefundNativeProjector implements NativeRefundProjectorInterface
{
    public $store;
    public $markers = array();
    public $financial = array();
    public $notes = array();
    public $orderPaid = true;
    public $refundedMinor = '0';
    public $stock = 7;
    public $emails = 0;
    public $preflightReason = null;
    public $preflightCalls = 0;
    public $crashAfterWrite = false;
    public $ambiguous = false;

    public function __construct(RefundMemoryStore $store) { $this->store = $store; }

    public function preflight(RefundIntent $intent): ?string
    {
        assertTrueValue($this->store->db->locked, 'Preflight reloads native state under payment lock.');
        $this->preflightCalls++;
        return $this->preflightReason;
    }

    public function inspect(string $operationId): NativeProjectionResult
    {
        assertTrueValue($this->store->db->locked, 'Native inspection is serialized.');
        $mode = $this->store->getIntent($operationId)->mode();
        if ($this->ambiguous) { return new NativeProjectionResult('ambiguous', $mode, null, null, null, null, null, 'manual_native_change'); }
        return isset($this->markers[$operationId]) ? $this->markers[$operationId] : new NativeProjectionResult('absent', $mode);
    }

    public function applyCompleted(RefundIntent $intent, VerifiedRefund $refund): NativeProjectionResult
    {
        assertSameValue('completed', $refund->status(), 'Only fresh completed cash permits native writes.');
        assertTrueValue($this->store->db->transactionDepth > 0, 'Native writes and marker have a transaction boundary.');
        $id = $intent->operationId();
        assertFalseValue(isset($this->markers[$id]), 'Never create a duplicate document or note.');
        if ($intent->mode() === 'overpayment') {
            $this->notes[$id] = $refund->principalDecimal();
            $result = new NativeProjectionResult('exact_match', 'overpayment', 'note_' . $id, null, null,
                $refund->principalDecimal(), $refund->currency() . '/' . $refund->network());
        } else {
            $draft = $intent->fullDraft();
            $this->financial[$id] = $draft['amount_minor'];
            $this->refundedMinor = $draft['amount_minor'];
            $this->orderPaid = false;
            $result = new NativeProjectionResult('exact_match', 'full', 'refund_' . $id, $draft['amount_minor'], $draft['currency']);
        }
        $this->markers[$id] = $result;
        if ($this->crashAfterWrite) {
            $this->crashAfterWrite = false;
            // Model a native idempotent row committed before a module binding (WHMCS).
            throw new RuntimeException('process ended after native row');
        }
        return $result;
    }
}

final class RefundScriptTransport implements TransportInterface
{
    public $responses;
    public $requests = array();
    public $db;

    public function __construct(array $responses, RefundMemoryDatabase $db) { $this->responses = $responses; $this->db = $db; }

    public function request($method, $url, array $headers, $body, $timeoutSeconds)
    {
        assertSameValue(0, $this->db->transactionDepth, 'No DB transaction may span provider HTTP.');
        assertTrueValue($this->db->locked, 'Fresh provider reads are under the payment lock.');
        if ($method === 'POST') {
            $payload = json_decode($body, true);
            $found = false;
            foreach ($this->db->rows as $row) {
                if ($row->externalOrderId() === $payload['external_order_id']) {
                    assertSameValue('unknown', $row->cashState(), 'Uncertainty is committed before provider HTTP.');
                    assertSameValue($row->mode(), $payload['mode'], 'Committed mode cannot change on replay.');
                    $found = true;
                }
            }
            assertTrueValue($found, 'The durable operation exists before the first POST.');
        }
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        if (!$this->responses) { throw new RuntimeException('No scripted response.'); }
        $next = array_shift($this->responses);
        if ($next instanceof Throwable) { throw $next; }
        return $next;
    }
}

function coordinator_intent($operation = 'over', $mode = 'overpayment', array $extra = array())
{
    return new RefundIntent(array_replace(array('operation_id' => $operation, 'request_token' => 'token_' . $operation,
        'installation_id' => 'installation', 'platform' => 'testcms', 'native_payment_key' => 'payment_one',
        'invoice_id' => 'inv_74BPZFhr9qy9Uz2fbRkdJX', 'project_id' => 'prj_one', 'environment' => 'sandbox',
        'mode' => $mode, 'full_draft' => $mode === 'full' ? array('amount_minor' => '10000', 'currency' => 'USD', 'restock' => false) : null), $extra));
}

function coordinator_wire(RefundIntent $intent, $status = 'created', $amount = '20', $id = null, array $extra = array())
{
    return array_replace(refund_test_wire($id ?: 'wdr_' . $intent->operationId(), $intent->mode(), $amount,
        $intent->externalOrderId(), $status), $extra);
}

function coordinator_reads(array $wire, array $invoiceExtra = array())
{
    return array(refund_test_response($wire), refund_test_response(array_replace(array('invoice_id' => $wire['invoice_id'],
        'project_id' => 'prj_one', 'is_test' => true, 'status' => 'paid_over'), $invoiceExtra)));
}

function coordinator_fixture(RefundIntent $intent, array $responses, &$store, &$native, &$transport)
{
    $store = new RefundMemoryStore(new RefundMemoryDatabase());
    $store->createIntent($intent);
    $native = new RefundNativeProjector($store);
    return coordinator_restart($store, $native, $responses, $transport);
}

function coordinator_restart(RefundMemoryStore $store, RefundNativeProjector $native, array $responses, &$transport)
{
    $transport = new RefundScriptTransport($responses, $store->db);
    $client = new Client(new ClientConfig('pk_test_key', 'sk_test_secret', 'https://api.paymos.test'), $transport,
        static function () { return 1709000000; });
    return new RefundCoordinator($client, $store, $native, static function () { return new DateTimeImmutable('@1709000000'); });
}

function coordinator_reload_store(RefundMemoryStore $store)
{
    $persisted = array();
    foreach ($store->db->rows as $id => $row) { $persisted[$id] = $row->toArray(); }
    $decoded = json_decode(json_encode($persisted), true);
    $restored = new RefundMemoryStore(new RefundMemoryDatabase());
    foreach ($decoded as $id => $data) { $restored->db->rows[$id] = new RefundIntent($data); }
    return $restored;
}

function test_refund_coordinator_preserves_identity_across_ton_format_change_without_new_cash()
{
    $raw = '0:ca6e321c7cce9ecedf0a8ca2492ec8592494aa5fb5ce0387dff96ef6af982a3e';
    $friendly = '0QDKbjIcfM6ezt8KjKJJLshZJJSqX7XOA4ff-W72r5gqPleK';
    $intent = coordinator_intent('ton-pinned', 'overpayment');
    $first = coordinator_wire($intent, 'created', '20', null, array('network' => 'TON', 'first_sender' => $raw, 'destination_address' => $raw));
    $coordinator = coordinator_fixture($intent, array_merge(array(refund_test_response($first)), coordinator_reads($first)), $store, $native, $transport);
    assertSameValue('created', $coordinator->submit('ton-pinned'), 'The original raw observation binds first.');
    $coordinator = coordinator_restart($store, $native, coordinator_reads(array_replace($first,
        array('first_sender' => $friendly, 'destination_address' => $friendly))), $transport);
    $coordinator->reconcile('ton-pinned');
    $current = $store->getIntent('ton-pinned');
    assertSameValue($friendly, $current->observation()->recipient(), 'Fresh verified observation is accepted for the same account.');
    assertSameValue('created', $current->cashState(), 'A representation change does not change cash state.');
    assertSameValue(array(), $native->financial, 'No financial operation is produced by the read.');
    foreach ($transport->requests as $request) { assertSameValue('GET', $request['method'], 'Recovery uses only GET.'); }

    $other = coordinator_wire($intent, 'created', '20', $first['withdrawal_id'],
        array('network' => 'TON', 'first_sender' => '0:' . str_repeat('0', 64), 'destination_address' => '0:' . str_repeat('0', 64)));
    $coordinator = coordinator_restart($store, $native, coordinator_reads($other), $transport);
    assertSameValue('show_review', $coordinator->reconcile('ton-pinned'), 'A different TON account still requires review.');
    assertSameValue(array(), $native->financial, 'A changed account cannot trigger a native refund.');
}


function test_refund_coordinator_lost_response_restart_keeps_committed_mode_key_and_one_binding()
{
    $intent = coordinator_intent();
    $coordinator = coordinator_fixture($intent, array(new RuntimeException('response lost after server commit')), $store, $native, $transport);
    assertSameValue('unknown', $coordinator->submit('over'), 'Lost response is uncertainty, not failure.');
    $first = $transport->requests[0];
    $temporaryNativeRequest = array('deleted');
    unset($temporaryNativeRequest, $coordinator);
    $restartedStore = coordinator_reload_store($store);
    $native->store = $restartedStore;
    $completed = coordinator_wire($intent, 'completed');
    $coordinator = coordinator_restart($restartedStore, $native,
        array_merge(array(refund_test_response($completed)), coordinator_reads($completed), coordinator_reads($completed)), $transport);
    assertSameValue('apply_completed', $coordinator->reconcile('over'), 'Unbound recovery replays the stable request then verifies cash.');
    assertSameValue($first['body'], $transport->requests[0]['body'], 'Restart uses exactly the same mode and key.');
    assertSameValue('wdr_over', $restartedStore->getIntent('over')->withdrawalId(), 'One write-once provider ID is recovered.');
    assertSameValue('resume_recovery', $coordinator->reconcile('over'), 'Repeated completion reuses the same cash note.');
    assertSameValue(array('over' => '20'), $native->notes, 'Only one cash note exists.');
    assertSameValue(1, count($restartedStore->db->rows), 'Timeout never creates another intent.');
    foreach ($transport->requests as $request) { refund_assert_signed($request); }
}

function test_refund_coordinator_restart_preserves_bound_money_evidence_and_original_native_draft()
{
    $intent = coordinator_intent('full', 'full');
    $amount = '120.000000000000000001';
    $wire = coordinator_wire($intent, 'created', $amount);
    $coordinator = coordinator_fixture($intent, array_merge(array(refund_test_response($wire)), coordinator_reads($wire)), $store, $native, $transport);
    assertSameValue('created', $coordinator->submit('full'), 'The original binding and exact evidence were persisted.');
    unset($coordinator);
    $store = coordinator_reload_store($store);
    $native->store = $store;
    $changed = coordinator_wire($intent, 'completed', '120.000000000000000002');
    $coordinator = coordinator_restart($store, $native, coordinator_reads($changed), $transport);
    assertSameValue('show_review', $coordinator->reconcile('full'), 'Restart cannot forget the original amount pin.');
    assertSameValue($amount, $store->getIntent('full')->observation()->principalDecimal(), 'Every original decimal survives serialization.');
    assertSameValue($intent->fullDraft(), $store->getIntent('full')->fullDraft(), 'Original native paid total and defaults survive restart.');
    assertSameValue('wdr_full', $store->getIntent('full')->withdrawalId(), 'Write-once binding survives restart.');
    assertSameValue(array(), $native->financial, 'Changed evidence cannot trigger a native refund after restart.');
}

function test_refund_coordinator_before_binding_uses_replay_and_reverse_read_not_callback_id()
{
    $intent = coordinator_intent('early', 'overpayment', array('cash_state' => 'unknown'));
    $wire = coordinator_wire($intent, 'completed', '20', 'wdr_authoritative');
    $coordinator = coordinator_fixture($intent, array_merge(array(refund_test_response($wire)), coordinator_reads($wire)), $store, $native, $transport);
    $forgedCallback = array('withdrawal_id' => 'wdr_forged', 'amount' => '500', 'project_id' => 'prj_foreign');
    assertSameValue('apply_completed', $coordinator->reconcile($intent->operationId()), 'Callback is only a wakeup for the stored operation.');
    assertSameValue('wdr_authoritative', $store->getIntent('early')->withdrawalId(), 'Binding comes from same-key replay and signed GET.');
    assertFalseValue(strpos(json_encode($transport->requests), $forgedCallback['withdrawal_id']) !== false, 'Callback ID is never requested or adopted.');
    assertSameValue('GET', $transport->requests[1]['method'], 'Cash is reverse-read.');
    assertTrueValue(strpos($transport->requests[2]['url'], '/v1/invoices/') !== false, 'Invoice read verifies project and environment.');
}

function test_refund_coordinator_wrong_project_before_binding_has_no_native_effects()
{
    $intent = coordinator_intent('foreign', 'overpayment', array('cash_state' => 'unknown'));
    $wire = coordinator_wire($intent, 'completed');
    $coordinator = coordinator_fixture($intent, array_merge(array(refund_test_response($wire)),
        coordinator_reads($wire, array('project_id' => 'prj_foreign'))), $store, $native, $transport);
    assertSameValue('show_review', $coordinator->reconcile('foreign'), 'Foreign project fails closed.');
    assertSameValue(null, $store->getIntent('foreign')->withdrawalId(), 'Foreign evidence cannot establish a binding.');
    assertSameValue(array(), $native->markers, 'Foreign cash produces no native mutation.');
}

function test_refund_coordinator_over20_then_full100_projects_original_native_total_without_rounding()
{
    $over = coordinator_intent();
    $created = coordinator_wire($over);
    $completed = coordinator_wire($over, 'completed');
    $full = coordinator_intent('full', 'full');
    $fullCreated = coordinator_wire($full, 'created', '100');
    $fullCompleted = coordinator_wire($full, 'completed', '100');
    $responses = array_merge(array(refund_test_response($created, 201)), coordinator_reads($created), coordinator_reads($completed),
        array(refund_test_response($fullCreated, 201)), coordinator_reads($fullCreated), coordinator_reads($fullCompleted));
    $coordinator = coordinator_fixture($over, $responses, $store, $native, $transport);
    assertSameValue('created', $coordinator->submit('over'), 'Accepted is pending.');
    assertSameValue(array(), $native->notes, 'Accepted does not create a note or refund.');
    assertSameValue('apply_completed', $coordinator->reconcile('over'), 'Completed overpayment creates a note.');
    assertSameValue(true, $native->orderPaid, 'Overpayment leaves the order paid.');
    assertSameValue('0', $native->refundedMinor, 'Overpayment does not consume native refunded total.');
    assertSameValue(7, $native->stock, 'Overpayment does not restock.');
    assertSameValue(0, $native->emails, 'Overpayment does not send native full-refund mail.');
    $store->createIntent($full);
    assertSameValue('created', $coordinator->submit('full'), 'Full remains possible after completed overpayment.');
    assertSameValue('apply_completed', $coordinator->reconcile('full'), 'Full cash completion projects the original native payment.');
    assertSameValue(array('full' => '10000'), $native->financial, 'Exactly one full100 native document, never an over20 financial document.');
    assertSameValue('10000', $native->refundedMinor, 'Full original native total is preserved.');
    $posts = array_values(array_filter($transport->requests, static function ($r) { return $r['method'] === 'POST'; }));
    assertSameValue(array('invoice_id', 'mode', 'external_order_id'), array_keys(json_decode($posts[1]['body'], true)), 'No FX or native amount goes to the provider.');
}

function test_refund_coordinator_full120_and_tiny_crypto_use_exact_original_native_minor_strings()
{
    foreach (array(array('120', '10000'), array('0.000000000000000001', '1'),
        array('12345678901234567890.123456789012345678', '1234567890123456789012345')) as $values) {
        $intent = coordinator_intent('full', 'full', array('full_draft' => array('amount_minor' => $values[1], 'currency' => 'JPY')));
        $wire = coordinator_wire($intent, 'completed', $values[0]);
        $coordinator = coordinator_fixture($intent, array_merge(array(refund_test_response($wire)), coordinator_reads($wire), coordinator_reads($wire)), $store, $native, $transport);
        assertSameValue('completed', $coordinator->submit('full'), 'Server amount remains exact.');
        assertSameValue('apply_completed', $coordinator->reconcile('full'), 'Verified cash permits a native full projection.');
        assertSameValue($values[1], $native->financial['full'], 'No ratio, float, rounding or currency conversion is applied.');
        assertSameValue($values[0], $store->getIntent('full')->observation()->principalDecimal(), 'Crypto evidence keeps every decimal.');
    }
}

function test_refund_coordinator_definite_failure_allows_explicit_new_action_and_late_old_id_is_isolated()
{
    $a = coordinator_intent('a');
    $aCreated = coordinator_wire($a);
    $aFailed = coordinator_wire($a, 'failed');
    $b = coordinator_intent('b', 'full');
    $bCompleted = coordinator_wire($b, 'completed', '120');
    $responses = array_merge(array(refund_test_response($aCreated)), coordinator_reads($aCreated), coordinator_reads($aFailed),
        array(refund_test_response($bCompleted)), coordinator_reads($bCompleted), coordinator_reads($bCompleted), coordinator_reads($aFailed));
    $coordinator = coordinator_fixture($a, $responses, $store, $native, $transport);
    $coordinator->submit('a');
    assertSameValue('show_failure', $coordinator->reconcile('a'), 'Only authoritative failed cash permits a new action.');
    assertSameValue('failed', $coordinator->submit('a'), 'Old failed intent never POSTs again.');
    $store->createIntent($b);
    assertSameValue('completed', $coordinator->submit('b'), 'New explicit action runs fresh preflight.');
    assertSameValue(2, $native->preflightCalls, 'Both independent actions run native guards.');
    assertSameValue('apply_completed', $coordinator->reconcile('b'), 'New action completes.');
    assertSameValue('show_failure', $coordinator->reconcile('a'), 'Late callback folds only the old ID.');
    assertSameValue('completed', $store->getIntent('b')->cashState(), 'Late old failure cannot overwrite full completion.');
    assertSameValue(array('b' => '10000'), $native->financial, 'No second native financial action.');
    assertSameValue(2, count($store->db->rows), 'Old intent and binding remain in history.');
}

function test_refund_coordinator_unknown_replays_when_new_actions_disabled_without_new_key()
{
    $intent = coordinator_intent();
    $wire = coordinator_wire($intent);
    $coordinator = coordinator_fixture($intent, array_merge(array(refund_test_problem(500, 'internal_error'),
        refund_test_response($wire)), coordinator_reads($wire)), $store, $native, $transport);
    assertSameValue('unknown', $coordinator->submit('over'), 'Server failure may have committed cash.');
    $native->preflightReason = 'refunds_not_enabled';
    assertSameValue('created', $coordinator->submit('over'), 'Unknown recovery bypasses new-action guards but keeps immutable payload.');
    assertSameValue(1, $native->preflightCalls, 'Only initial new submission runs create preflight.');
    assertSameValue($transport->requests[0]['body'], $transport->requests[1]['body'], 'Replay uses the same key and mode.');
}

function test_refund_coordinator_unknown_does_not_allow_a_second_intent()
{
    $intent = coordinator_intent();
    $coordinator = coordinator_fixture($intent, array(new RuntimeException('timeout')), $store, $native, $transport);
    $coordinator->submit('over');
    $threw = false;
    try { $store->createIntent(coordinator_intent('new', 'full')); } catch (RuntimeException $error) { $threw = true; }
    assertTrueValue($threw, 'Unresolved cash blocks a new local action.');
    assertSameValue(1, count($transport->requests), 'No automatic new cash retry or key.');
}

function test_refund_coordinator_binding_cas_conflict_never_overwrites_or_projects()
{
    $intent = coordinator_intent();
    $wire = coordinator_wire($intent, 'completed');
    $coordinator = coordinator_fixture($intent, array_merge(array(refund_test_response($wire)), coordinator_reads($wire)), $store, $native, $transport);
    $store->db->bindingConflict = true;
    assertSameValue('review', $coordinator->submit('over'), 'CAS conflict enters review.');
    assertSameValue('show_review', $coordinator->reconcile('over'), 'Unbound review never repeats the provider POST.');
    assertSameValue(null, $store->getIntent('over')->withdrawalId(), 'Binding was not overwritten.');
    assertSameValue(array(), $native->markers, 'Conflict blocks native effects.');
}

function test_refund_coordinator_bound_money_recipient_mode_scope_and_id_cannot_change()
{
    $changes = array(array('amount' => '21'), array('currency' => 'USDC'), array('network' => 'ERC20'),
        array('first_sender' => 'TSecondSender', 'destination_address' => 'TSecondSender'), array('mode' => 'full'),
        array('external_order_id' => 'foreign-key'), array('withdrawal_id' => 'wdr_other'),
        array('invoice_id' => 'inv_other'), array('is_test' => false), array('project_id' => 'prj_foreign'));
    foreach ($changes as $change) {
        $intent = coordinator_intent();
        $wire = coordinator_wire($intent);
        $changed = coordinator_wire($intent, 'completed', '20', null, $change);
        $coordinator = coordinator_fixture($intent, array_merge(array(refund_test_response($wire)), coordinator_reads($wire),
            coordinator_reads($changed)), $store, $native, $transport);
        $coordinator->submit('over');
        assertSameValue('show_review', $coordinator->reconcile('over'), 'Changed immutable evidence fails closed: ' . key($change));
        assertSameValue('wdr_over', $store->getIntent('over')->withdrawalId(), 'Existing binding is preserved.');
        assertSameValue('20', $store->getIntent('over')->observation()->principalDecimal(), 'Original exact amount is pinned.');
        assertSameValue(array(), $native->markers, 'No effects on inconsistent evidence.');
    }
}

function test_refund_coordinator_rewind_and_recompletion_keep_native_document_and_effects()
{
    $intent = coordinator_intent('full', 'full');
    $completed = coordinator_wire($intent, 'completed', '120');
    $rewound = coordinator_wire($intent, 'signed', '120');
    $coordinator = coordinator_fixture($intent, array_merge(array(refund_test_response($completed)), coordinator_reads($completed),
        coordinator_reads($completed), coordinator_reads($rewound), coordinator_reads($completed)), $store, $native, $transport);
    $coordinator->submit('full');
    assertSameValue('apply_completed', $coordinator->reconcile('full'), 'First completion creates a document.');
    assertSameValue('show_review', $coordinator->reconcile('full'), 'Cash rewind requires review, preserving native history.');
    assertSameValue('processing', $store->getIntent('full')->cashState(), 'Current cash status follows authoritative GET.');
    assertSameValue('native_review', $store->getIntent('full')->projectionState(), 'Historical native document remains under review.');
    assertSameValue('resume_recovery', $coordinator->reconcile('full'), 'Recompletion reuses the deterministic marker.');
    assertSameValue(1, count($native->financial), 'No second native refund.');
    assertSameValue(0, $native->emails, 'Recompletion cannot automatically resend mail.');
}

function test_refund_coordinator_native_crash_recovers_exact_marker_without_second_write()
{
    $intent = coordinator_intent('full', 'full');
    $wire = coordinator_wire($intent, 'completed', '120');
    $coordinator = coordinator_fixture($intent, array_merge(array(refund_test_response($wire)), coordinator_reads($wire),
        coordinator_reads($wire), coordinator_reads($wire)), $store, $native, $transport);
    $coordinator->submit('full');
    $native->crashAfterWrite = true;
    assertSameValue('show_review', $coordinator->reconcile('full'), 'Ambiguous native write is not blindly retried.');
    assertSameValue('resume_recovery', $coordinator->reconcile('full'), 'Exact persisted native marker repairs the module binding.');
    assertSameValue(1, count($native->financial), 'Recovery reuses the native row.');
    assertSameValue('applied', $store->getIntent('full')->projectionState(), 'Binding is recovered.');
}

function test_refund_coordinator_recompletion_with_missing_historical_marker_cannot_write_again()
{
    $intent = coordinator_intent('full', 'full');
    $wire = coordinator_wire($intent, 'completed', '120');
    $rewound = coordinator_wire($intent, 'signed', '120');
    $coordinator = coordinator_fixture($intent, array_merge(array(refund_test_response($wire)), coordinator_reads($wire),
        coordinator_reads($wire), coordinator_reads($rewound), coordinator_reads($wire)), $store, $native, $transport);
    $coordinator->submit('full');
    assertSameValue('apply_completed', $coordinator->reconcile('full'), 'Native completion was committed.');
    assertSameValue('show_review', $coordinator->reconcile('full'), 'Cash rewind retains historical projection.');
    unset($native->markers['full']);
    assertSameValue('show_review', $coordinator->reconcile('full'), 'Missing historical proof cannot authorize another native refund.');
    assertSameValue('native_marker_missing', $store->getIntent('full')->toArray()['review_reason'], 'Missing marker is explicit.');
    assertSameValue(1, count($native->financial), 'No second financial write after reorg.');
}

function test_refund_coordinator_ambiguous_native_marker_stops_before_writes()
{
    $intent = coordinator_intent('full', 'full');
    $wire = coordinator_wire($intent, 'completed', '120');
    $coordinator = coordinator_fixture($intent, array_merge(array(refund_test_response($wire)), coordinator_reads($wire), coordinator_reads($wire)), $store, $native, $transport);
    $coordinator->submit('full');
    $native->ambiguous = true;
    assertSameValue('show_review', $coordinator->reconcile('full'), 'Manual/ambiguous native document requires review.');
    assertSameValue(array(), $native->financial, 'No duplicate native write is attempted.');
}

function test_refund_coordinator_current_capability_or_native_changes_refuse_before_post()
{
    foreach (array('capability_missing', 'native_partial_refund', 'native_paid_total_changed', 'refunds_not_enabled') as $reason) {
        $intent = coordinator_intent('full', 'full');
        $coordinator = coordinator_fixture($intent, array(), $store, $native, $transport);
        $native->preflightReason = $reason;
        assertSameValue('review', $coordinator->submit('full'), 'Fresh preflight refuses ' . $reason);
        assertSameValue(array(), $transport->requests, 'Refused action never sends provider cash.');
        assertSameValue($reason, $store->getIntent('full')->toArray()['review_reason'], 'Reason is persisted.');
    }
}

function test_refund_coordinator_cancelled_and_rejected_requests_do_not_retry_automatically()
{
    $intent = coordinator_intent();
    $wire = coordinator_wire($intent, 'cancelled');
    $coordinator = coordinator_fixture($intent, array_merge(array(refund_test_response($wire)), coordinator_reads($wire), coordinator_reads($wire)), $store, $native, $transport);
    assertSameValue('cancelled', $coordinator->submit('over'), 'Authoritative cancellation is terminal for this intent.');
    assertSameValue('cancelled', $coordinator->submit('over'), 'Repeated submit does not initiate another cash operation.');
    assertSameValue('show_cancelled', $coordinator->reconcile('over'), 'Cancelled action is exposed.');
    assertSameValue(array(), $native->markers, 'Cancelled cash never projects.');

    $coordinator = coordinator_fixture(coordinator_intent(), array(refund_test_problem(409, 'refund_no_overpayment')), $store, $native, $transport);
    assertSameValue('failed', $coordinator->submit('over'), 'Definite rejection before acceptance is recorded.');
    assertSameValue('failed', $coordinator->submit('over'), 'A new explicit intent is required after rejection.');
    assertSameValue(1, count($transport->requests), 'No automatic resubmission after rejection.');
}

function test_refund_coordinator_missing_id_during_reverse_read_blocks_native_projection()
{
    $intent = coordinator_intent();
    $wire = coordinator_wire($intent, 'completed');
    $coordinator = coordinator_fixture($intent, array(refund_test_response($wire), refund_test_problem(404, 'not_found')), $store, $native, $transport);
    assertSameValue('review', $coordinator->submit('over'), 'A POST response alone cannot authorize native effects.');
    assertSameValue(array(), $native->markers, 'Failed reverse-read produces no financial action.');
}

function test_refund_coordinator_unknown_replay_without_id_remains_pending_without_native_effects()
{
    foreach (array(array('status' => 'created'), array('withdrawal_id' => null), array('withdrawal_id' => '')) as $response) {
        $intent = coordinator_intent('unknown_no_id', 'overpayment', array('cash_state' => RefundStates::UNKNOWN));
        $coordinator = coordinator_fixture($intent, array(refund_test_response($response)), $store, $native, $transport);

        assertSameValue(RefundStates::SHOW_PENDING, $coordinator->reconcile('unknown_no_id'), 'An unresolved replay stays pending.');
        $current = $store->getIntent('unknown_no_id');
        assertSameValue(RefundStates::UNKNOWN, $current->cashState(), 'An absent withdrawal ID does not prove cash state.');
        assertSameValue(null, $current->withdrawalId(), 'An absent withdrawal ID cannot establish a binding.');
        assertSameValue(null, $current->observation(), 'An absent withdrawal ID cannot establish evidence.');
        assertSameValue(1, count($transport->requests), 'Only the committed replay is sent.');
        assertSameValue('POST', $transport->requests[0]['method'], 'No reverse read is attempted without an ID.');
        assertSameValue(array(), $native->markers, 'Missing ID prevents native projection.');
        assertSameValue(array(), $native->financial, 'Missing ID cannot create a financial document.');
        assertSameValue(array(), $native->notes, 'Missing ID cannot create a native note.');
    }
}

function test_refund_coordinator_not_submitted_reconcile_does_not_initiate_cash()
{
    $coordinator = coordinator_fixture(coordinator_intent(), array(), $store, $native, $transport);
    assertSameValue('none', $coordinator->reconcile('over'), 'An unsolicited callback cannot submit a new intent.');
    assertSameValue(array(), $transport->requests, 'No provider POST on an unsubmitted intent.');
}
