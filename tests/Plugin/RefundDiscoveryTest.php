<?php

declare(strict_types=1);

use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Http\HttpResponse;
use Paymos\Http\MockTransport;
use Paymos\Plugin\RefundDiscovery;

function test_refund_discovery_uses_scoped_reads_and_rotates_the_cursor_after_a_broken_mapping()
{
    $signal = array('type' => 'refund', 'withdrawal_id' => 'wdr_external', 'invoice_id' => 'inv_paid', 'project_id' => 'prj_cms',
        'is_test' => true, 'mode' => 'overpayment', 'external_order_id' => 'dashboard-key');
    $transport = new MockTransport(array(new HttpResponse(200, json_encode(array('items' => array($signal, array_replace($signal, array('withdrawal_id' => 'wdr_retry'))), 'next_cursor' => 'cursor-b')), array())));
    $client = new Client(new ClientConfig('pk_test_key', 'sk_test_secret', 'https://api.paymos.test'), $transport);
    $seen = array();
    $result = RefundDiscovery::page($client, 'sandbox', 'cursor-a', static function ($item, $environment) use (&$seen) {
        assertSameValue('sandbox', $environment, 'Discovery cannot cross environments.');
        $seen[] = $item['withdrawal_id'];
        if ($item['withdrawal_id'] === 'wdr_retry') { throw new RuntimeException('native mapping unavailable'); }
    });
    assertSameValue(array('wdr_external', 'wdr_retry'), $seen, 'Unbound external refunds reach independent read verification.');
    assertSameValue('cursor-b', $result['next_cursor'], 'One unrelated mapping cannot starve all historical pages.');
    assertSameValue(array('wdr_retry'), $result['errors'], 'Failed observation is explicit and retried on the next rotating sweep.');
    $request = $transport->requests()[0];
    assertSameValue('GET', $request['method'], 'Discovery never submits money.');
    assertTrueValue(strpos($request['url'], '/v1/refunds?') !== false, 'Uses the Payment-key refund resource.');
}

function test_refund_discovery_does_not_deliver_cross_environment_signals()
{
    $transport = new MockTransport(array(new HttpResponse(200, json_encode(array('items' => array(array('withdrawal_id' => 'wdr_foreign', 'invoice_id' => 'inv_paid', 'project_id' => 'prj_cms', 'mode' => 'full', 'external_order_id' => 'key', 'is_test' => false)), 'next_cursor' => null)), array())));
    $client = new Client(new ClientConfig('pk_test_key', 'sk_test_secret', 'https://api.paymos.test'), $transport);
    $called = false;
    $result = RefundDiscovery::page($client, 'sandbox', null, static function () use (&$called) { $called = true; });
    assertFalseValue($called, 'Cross-environment signals must be rejected before calling the native adapter.');
    assertSameValue(0, $result['checked'], 'Foreign cash evidence is not delivered.');
    assertSameValue(array('wdr_foreign'), $result['errors'], 'Foreign evidence is rejected explicitly.');
}

function test_refund_discovery_expired_cursor_clears_only_the_invalid_cursor_without_any_cash_attempt()
{
    $transport = new MockTransport(array(new HttpResponse(400, '{"type":"about:blank","title":"Bad Request","status":400,"code":"pagination_cursor_invalid","detail":"Cursor is invalid or expired."}', array())));
    $client = new Client(new ClientConfig('pk_test_key', 'sk_test_secret', 'https://api.paymos.test'), $transport);
    $called = false;
    $result = RefundDiscovery::page($client, 'sandbox', 'expired-cursor', static function () use (&$called) { $called = true; });
    assertSameValue(null, $result['next_cursor'], 'The persisted cursor is cleared so the next cron starts from the beginning.');
    assertSameValue(true, $result['cursor_reset'], 'Expired cursor reset is explicit.');
    assertFalseValue($called, 'Cursor reset cannot invent a refund observation.');
    assertSameValue(1, count($transport->requests()), 'No automatic duplicate read or cash request while resetting.');
    assertSameValue('GET', $transport->requests()[0]['method'], 'Reset remains read-only.');
}

function test_refund_discovery_other_api_errors_preserve_the_callers_cursor_and_propagate()
{
    $transport = new MockTransport(array(new HttpResponse(400, '{"type":"about:blank","title":"Bad Request","status":400,"code":"field_invalid_format","detail":"Bad filter."}', array())));
    $client = new Client(new ClientConfig('pk_test_key', 'sk_test_secret', 'https://api.paymos.test'), $transport);
    try { RefundDiscovery::page($client, 'sandbox', 'still-valid', static function () {}); }
    catch (\Paymos\Exception\ApiException $error) {
        assertSameValue('field_invalid_format', $error->errorCode(), 'Only an actual invalid-cursor error resets pagination.');
        return;
    }
    throw new RuntimeException('A non-cursor failure must propagate without a new persisted cursor.');
}
