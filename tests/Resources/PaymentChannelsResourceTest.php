<?php

declare(strict_types=1);

use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Http\HttpResponse;
use Paymos\Http\MockTransport;

/**
 * Channels and their deposit feed live in ONE test file on purpose: the channel
 * list terminates on a null cursor and the deposit feed never terminates at all,
 * and keeping both assertions side by side is what keeps that distinction
 * visible to the next reader.
 */

/**
 * @param HttpResponse[] $responses
 * @return array{0: Client, 1: MockTransport}
 */
function paymos_pc_client(array $responses)
{
    $transport = new MockTransport($responses);
    $client = new Client(
        new ClientConfig('rk_test_key', 'sk_test_secret', 'https://api.paymos.io', 30),
        $transport,
        static function () {
            return 1709000000;
        }
    );

    return array($client, $transport);
}

/** @param bool $accepting */
function paymos_pc_channel_json($accepting = true)
{
    return '{"id":"pc_1","project_id":"prj_1","external_id":"customer-42","status":"active",'
        . '"is_accepting_payments":' . ($accepting ? 'true' : 'false') . ','
        . '"is_fully_provisioned":true,"is_test":true,"applied_fee_percent":1.5,"customer_fee_percent":0,'
        . '"networks":[{"network":"TRC20","status":"provisioned","address":"TAbc",'
        . '"tokens":[{"symbol":"USDT","minimum_deposit":"1.00"}]}],'
        . '"created_at":1709000000,"updated_at":1709000100}';
}

/** Nullable members are OMITTED by the server, never sent as null. */
function paymos_pc_deposit_json()
{
    return '{"id":"pcd_1","payment_channel_id":"pc_1","project_id":"prj_1",'
        . '"payment_channel_external_id":"customer-42","status":"confirmed","is_final":true,"is_test":true,'
        . '"currency":"USDT","network":"TRC20","chain_id":728126428,'
        . '"gross":"100.00","fee":"0.50","net":"99.50","applied_fee_percent":0.5,"customer_fee_percent":0,'
        . '"tx_hash":"0xabc","transfer_id":"trf_1","destination_address":"TAbc","block_height":12345,'
        . '"created_at":1709000000,"updated_at":1709000100,"confirmed_at":1709000100}';
}

function test_payment_channels_create_twice_returns_the_channel_both_times()
{
    list($client, $transport) = paymos_pc_client(array(
        new HttpResponse(201, paymos_pc_channel_json(), array()),
        new HttpResponse(200, paymos_pc_channel_json(), array()),
    ));

    $payload = array('project_id' => 'prj_1', 'external_id' => 'customer-42');
    $created = $client->paymentChannels()->create($payload);
    $replayed = $client->paymentChannels()->create($payload);

    assertSameValue('pc_1', $created['id'], 'A new channel is 201 with the channel body.');
    assertSameValue(
        'pc_1',
        $replayed['id'],
        'Repeating project_id + external_id answers 200 with the SAME channel — never an error.'
    );

    $requests = $transport->requests();
    assertSameValue('POST', $requests[0]['method'], 'Channel create must POST.');
    assertSameValue(
        'https://api.paymos.io/v1/payment-channels',
        $requests[0]['url'],
        'Channel create must use the public v1 path.'
    );
    assertSameValue(
        '{"project_id":"prj_1","external_id":"customer-42"}',
        $requests[0]['body'],
        'Channel create body is exactly project_id and external_id.'
    );
}

function test_payment_channels_block_returns_the_updated_channel()
{
    list($client, $transport) = paymos_pc_client(array(
        new HttpResponse(200, paymos_pc_channel_json(false), array()),
    ));

    $channel = $client->paymentChannels()->block('pc_1');

    assertFalseValue(
        $channel['is_accepting_payments'],
        'Block is not a void call — it returns the channel reporting is_accepting_payments false.'
    );
    $requests = $transport->requests();
    assertSameValue('POST', $requests[0]['method'], 'Channel block must POST.');
    assertSameValue(
        'https://api.paymos.io/v1/payment-channels/pc_1/block',
        $requests[0]['url'],
        'Channel block must use the public v1 path.'
    );
    assertSameValue('', $requests[0]['body'], 'Channel block carries no body.');
}

function test_payment_channels_unblock_returns_the_updated_channel()
{
    list($client, $transport) = paymos_pc_client(array(
        new HttpResponse(200, paymos_pc_channel_json(true), array()),
    ));

    $channel = $client->paymentChannels()->unblock('pc_1');

    assertTrueValue(
        $channel['is_accepting_payments'],
        'Unblock is not a no-op — it returns the channel accepting payments again.'
    );
    $requests = $transport->requests();
    assertSameValue('POST', $requests[0]['method'], 'Channel unblock must POST.');
    assertSameValue(
        'https://api.paymos.io/v1/payment-channels/pc_1/unblock',
        $requests[0]['url'],
        'Channel unblock must use the public v1 path.'
    );
}

function test_payment_channels_get_returns_the_raw_snake_case_channel()
{
    list($client, $transport) = paymos_pc_client(array(
        new HttpResponse(200, paymos_pc_channel_json(), array()),
    ));

    $channel = $client->paymentChannels()->get('pc_1');

    assertSameValue('TRC20', $channel['networks'][0]['network'], 'Networks carry the rail code, not a nickname.');
    assertSameValue(
        '1.00',
        $channel['networks'][0]['tokens'][0]['minimum_deposit'],
        'minimum_deposit stays a decimal string.'
    );
    $requests = $transport->requests();
    assertSameValue('GET', $requests[0]['method'], 'Channel get must GET.');
    assertSameValue(
        'https://api.paymos.io/v1/payment-channels/pc_1',
        $requests[0]['url'],
        'Channel get must use the public v1 path.'
    );
}

function test_payment_channels_list_filters_reach_the_wire_in_snake_case()
{
    list($client, $transport) = paymos_pc_client(array(
        new HttpResponse(200, '{"items":[' . paymos_pc_channel_json() . '],"next_cursor":null}', array()),
    ));

    $page = $client->paymentChannels()->listPage(array(
        'cursor' => 'cur_1',
        'limit' => 2,
        'status' => array('blocked', 'active'),
        'external_id' => 'customer-42',
        'project_id' => 'prj_1',
    ));

    assertSameValue('pc_1', $page['items'][0]['id'], 'The channel list decodes into raw arrays.');
    assertSameValue(null, $page['next_cursor'], 'The channel list is an ordinary keyset page: null ends it.');
    $requests = $transport->requests();
    assertSameValue(
        'https://api.paymos.io/v1/payment-channels'
        . '?cursor=cur_1&external_id=customer-42&limit=2&project_id=prj_1&status=active&status=blocked',
        $requests[0]['url'],
        'Every channel filter must reach the wire in snake_case, with status repeated — '
        . 'the API rejects unknown query fields, so a camelCase slip is a 400 for the merchant.'
    );
}

function test_payment_channels_list_rejects_an_unknown_filter()
{
    list($client, $_transport) = paymos_pc_client(array());

    $threw = false;
    try {
        $client->paymentChannels()->listPage(array('external_order_id' => 'order-1'));
    } catch (\InvalidArgumentException $e) {
        $threw = true;
    }

    assertTrueValue($threw, 'The channel filter set is closed — an unknown field must fail locally, not as a 400.');
}

function test_payment_channels_iterator_stops_on_a_null_cursor()
{
    list($client, $transport) = paymos_pc_client(array(
        new HttpResponse(200, '{"items":[{"id":"pc_1"}],"next_cursor":"opaque+/="}', array()),
        new HttpResponse(200, '{"items":[{"id":"pc_2"}],"next_cursor":null}', array()),
    ));

    $items = iterator_to_array($client->paymentChannels()->iterate(array(
        'status' => array('active'),
        'limit' => 1,
    )), false);

    assertSameValue('pc_1', $items[0]['id'], 'Iterator must yield the first page.');
    assertSameValue('pc_2', $items[1]['id'], 'Iterator must follow next_cursor.');
    assertSameValue(2, count($items), 'Iterator must stop once next_cursor is null.');
    $requests = $transport->requests();
    assertSameValue(2, count($requests), 'A null cursor ends the channel list — no third request.');
    assertSameValue(
        'https://api.paymos.io/v1/payment-channels?limit=1&status=active',
        $requests[0]['url'],
        'First iterator request must carry the stable filters.'
    );
    assertSameValue(
        'https://api.paymos.io/v1/payment-channels?cursor=opaque%2B%2F%3D&limit=1&status=active',
        $requests[1]['url'],
        'Next iterator request must add the RFC 3986 encoded cursor and preserve filters.'
    );
}

function test_payment_channels_iterator_rejects_a_repeated_cursor()
{
    list($client, $_transport) = paymos_pc_client(array(
        new HttpResponse(200, '{"items":[{"id":"pc_1"}],"next_cursor":"cur_1"}', array()),
        new HttpResponse(200, '{"items":[{"id":"pc_2"}],"next_cursor":"cur_1"}', array()),
    ));

    $threw = false;
    try {
        iterator_to_array($client->paymentChannels()->iterate(), false);
    } catch (\RuntimeException $e) {
        $threw = true;
    }

    assertTrueValue($threw, 'The same cursor twice is a server fault, not an infinite loop.');
}

function test_payment_channels_simulate_deposit_sends_network_and_omits_stage()
{
    list($client, $transport) = paymos_pc_client(array(
        new HttpResponse(200, paymos_pc_deposit_json(), array()),
    ));

    $deposit = $client->paymentChannels()->simulateDeposit('pc_1', array(
        'amount' => '100.00',
        'currency' => 'USDT',
        'network' => 'TRC20',
    ));

    assertSameValue('pcd_1', $deposit['id'], 'Simulate returns the deposit it created.');
    assertSameValue('100.00', $deposit['gross'], 'Money stays a decimal string, never a float.');
    $requests = $transport->requests();
    assertSameValue(
        'https://api.paymos.io/v1/sandbox/payment-channels/pc_1/simulate-deposit',
        $requests[0]['url'],
        'Simulate must use the sandbox v1 path.'
    );
    assertSameValue(
        '{"amount":"100.00","currency":"USDT","network":"TRC20"}',
        $requests[0]['body'],
        'network is required and must reach the wire.'
    );
    assertFalseValue(
        strpos($requests[0]['body'], 'stage') !== false,
        'An unset stage is omitted from the body entirely — not null, not "".'
    );
}

function test_payment_channels_simulate_deposit_sends_an_explicit_stage()
{
    list($client, $transport) = paymos_pc_client(array(
        new HttpResponse(200, paymos_pc_deposit_json(), array()),
    ));

    $client->paymentChannels()->simulateDeposit('pc_1', array(
        'amount' => '100.00',
        'currency' => 'USDT',
        'network' => 'TRC20',
        'stage' => 'confirming',
    ));

    $requests = $transport->requests();
    assertSameValue(
        '{"amount":"100.00","currency":"USDT","network":"TRC20","stage":"confirming"}',
        $requests[0]['body'],
        'An explicit stage is sent as given.'
    );
}

function test_payment_channels_simulate_deposit_validates_its_body_locally()
{
    list($client, $_transport) = paymos_pc_client(array());

    $rejected = array(
        'missing network' => array('amount' => '100.00', 'currency' => 'USDT'),
        'empty network' => array('amount' => '100.00', 'currency' => 'USDT', 'network' => ' '),
        'float amount' => array('amount' => 100.0, 'currency' => 'USDT', 'network' => 'TRC20'),
        'unknown stage' => array(
            'amount' => '100.00', 'currency' => 'USDT', 'network' => 'TRC20', 'stage' => 'settled',
        ),
        'unknown field' => array(
            'amount' => '100.00', 'currency' => 'USDT', 'network' => 'TRC20', 'chain_id' => 728126428,
        ),
    );

    foreach ($rejected as $case => $payload) {
        $threw = false;
        try {
            $client->paymentChannels()->simulateDeposit('pc_1', $payload);
        } catch (\InvalidArgumentException $e) {
            $threw = true;
        }
        assertTrueValue($threw, 'simulateDeposit must reject ' . $case . ' locally.');
    }
}

function test_payment_channel_ids_are_path_encoded_not_query_encoded()
{
    list($client, $transport) = paymos_pc_client(array(
        new HttpResponse(200, paymos_pc_channel_json(), array()),
        new HttpResponse(200, paymos_pc_deposit_json(), array()),
    ));

    $client->paymentChannels()->get('pc_a b~c');
    $client->paymentChannelDeposits()->get('pcd_a b~c');

    $requests = $transport->requests();
    assertSameValue(
        'https://api.paymos.io/v1/payment-channels/pc_a%20b~c',
        $requests[0]['url'],
        'Channel ids take RFC 3986 path encoding (%20, bare ~), not the query encoder\'s "+" and %7E.'
    );
    assertSameValue(
        'https://api.paymos.io/v1/payment-channel-deposits/pcd_a%20b~c',
        $requests[1]['url'],
        'Deposit ids take RFC 3986 path encoding too — the signature is computed over this exact path.'
    );
}

function test_payment_channel_deposits_empty_feed_page_still_yields_a_cursor()
{
    list($client, $_transport) = paymos_pc_client(array(
        new HttpResponse(200, '{"items":[],"next_cursor":"cur_2"}', array()),
    ));

    $page = $client->paymentChannelDeposits()->readPage();

    assertSameValue(array(), $page['items'], 'A quiet page has no items.');
    assertSameValue(
        'cur_2',
        $page['next_cursor'],
        'The feed never ends: an empty page still advances past the global positions this merchant '
        . 'skipped. Anything that treats an empty page as the end has modelled the feed on a list.'
    );
}

function test_payment_channel_deposits_blocked_page_surfaces_deposit_and_reason()
{
    list($client, $_transport) = paymos_pc_client(array(
        new HttpResponse(
            200,
            '{"items":[],"next_cursor":"cur_2","blocked":{"deposit_id":"pcd_9","reason":"not_confirmed"}}',
            array()
        ),
    ));

    $page = $client->paymentChannelDeposits()->readPage();

    assertSameValue(
        'pcd_9',
        $page['blocked']['deposit_id'],
        'Without blocked, a stuck feed is byte-identical to a quiet day.'
    );
    assertSameValue('not_confirmed', $page['blocked']['reason'], 'The stall reason must reach the caller.');
    assertSameValue('cur_2', $page['next_cursor'], 'blocked explains a stall; it never changes cursor semantics.');
}

function test_payment_channel_deposits_feed_filters_reach_the_wire_in_snake_case()
{
    list($client, $transport) = paymos_pc_client(array(
        new HttpResponse(200, '{"items":[' . paymos_pc_deposit_json() . '],"next_cursor":"cur_2"}', array()),
    ));

    $page = $client->paymentChannelDeposits()->readPage(array(
        'cursor' => 'cur_1',
        'limit' => 50,
        'project_id' => 'prj_1',
        'payment_channel_id' => 'pc_1',
        'confirmed_from' => 1709000000,
    ));

    assertSameValue('pcd_1', $page['items'][0]['id'], 'The feed decodes into raw arrays.');
    $requests = $transport->requests();
    assertSameValue(
        'https://api.paymos.io/v1/payment-channel-deposits'
        . '?confirmed_from=1709000000&cursor=cur_1&limit=50&payment_channel_id=pc_1&project_id=prj_1',
        $requests[0]['url'],
        'Every feed filter must reach the wire in snake_case — the API rejects unknown query fields.'
    );
}

function test_payment_channel_deposits_omit_absent_optional_members()
{
    list($client, $_transport) = paymos_pc_client(array(
        new HttpResponse(200, paymos_pc_deposit_json(), array()),
    ));

    $deposit = $client->paymentChannelDeposits()->get('pcd_1');

    assertTrueValue($deposit['is_final'], 'A confirmed deposit is final.');
    assertFalseValue(
        array_key_exists('source_address', $deposit),
        'Null members are omitted from the JSON, not sent as null — read them with isset/array_key_exists.'
    );
    assertFalseValue(
        array_key_exists('contract_address', $deposit),
        'Same for contract_address: absent, never null.'
    );
}

function test_payment_channel_deposits_expose_no_iterator()
{
    list($client, $_transport) = paymos_pc_client(array());
    $deposits = $client->paymentChannelDeposits();

    assertFalseValue(
        method_exists($deposits, 'iterate'),
        'The feed must NOT get a generator: one that stops on an empty cursor silently halts a '
        . 'merchant\'s reconciliation on the first quiet minute, and one that does not never ends.'
    );
    assertFalseValue(
        method_exists($deposits, 'listPage'),
        'The feed reader is readPage() — the name itself says this is not a list.'
    );
}
