<?php

declare(strict_types=1);

use Paymos\Connect\DeviceConnectClient;
use Paymos\Http\HttpResponse;
use Paymos\Http\MockTransport;

function test_device_connect_start_builds_official_verification_url()
{
    $transport = new MockTransport(array(new HttpResponse(200, json_encode(array(
        'device_code' => 'device-secret',
        'user_code' => 'ABCD-EFGH',
        'verification_uri' => 'https://app.paymos.io/connect/cms/verify',
        'verification_uri_complete' => 'https://app.paymos.io/connect/cms/verify?user_code=ABCD-EFGH',
        'expires_in' => 600,
        'interval' => 5,
    )), array())));
    $client = new DeviceConnectClient('https://app.paymos.io', $transport);

    $state = $client->start('woocommerce', 'https://shop.example/');

    assertSameValue('device-secret', $state['device_code'], 'Device code must be retained server-side.');
    assertTrueValue(strpos($state['verification_url'], 'cms=woocommerce') !== false, 'CMS slug must be added to approval URL.');
    assertTrueValue(strpos($state['verification_url'], 'source_url=https%3A%2F%2Fshop.example') !== false, 'Store URL must be added to approval URL.');
    $request = $transport->requests()[0];
    assertSameValue('https://app.paymos.io/connect/cms/device', $request['url'], 'Device endpoint must be fixed.');
    assertTrueValue(strpos($request['body'], 'client_id=paymos-cms') !== false, 'Public CMS client id must be sent.');
}

function test_device_connect_start_carries_return_url_and_drops_unusable_one()
{
    $payload = json_encode(array(
        'device_code' => 'device-secret',
        'user_code' => 'ABCD-EFGH',
        'verification_uri' => 'https://app.paymos.io/connect/cms/verify',
        'verification_uri_complete' => 'https://app.paymos.io/connect/cms/verify?user_code=ABCD-EFGH',
        'expires_in' => 600,
        'interval' => 5,
    ));

    // The admin page carries a query string, so it must survive encoding intact.
    $transport = new MockTransport(array(new HttpResponse(200, $payload, array())));
    $client = new DeviceConnectClient('https://app.paymos.io', $transport);
    $state = $client->start(
        'woocommerce',
        'https://shop.example/',
        'https://shop.example/wp-admin/admin.php?page=wc-settings&section=paymos'
    );
    assertTrueValue(
        strpos($state['verification_url'], 'return_url=https%3A%2F%2Fshop.example%2Fwp-admin%2Fadmin.php%3Fpage%3Dwc-settings%26section%3Dpaymos') !== false,
        'Admin return URL must be forwarded to the approval screen.'
    );

    // A non-HTTPS return URL is dropped rather than sent: the server would refuse it
    // anyway, and silently omitting it keeps the merchant on the working fallback.
    $transport = new MockTransport(array(new HttpResponse(200, $payload, array())));
    $client = new DeviceConnectClient('https://app.paymos.io', $transport);
    $state = $client->start('woocommerce', 'https://shop.example/', 'http://shop.example/wp-admin/');
    assertTrueValue(
        strpos($state['verification_url'], 'return_url=') === false,
        'A non-HTTPS return URL must not be forwarded.'
    );

    // Omitting it entirely stays valid — older plugin builds call start() with two arguments.
    $transport = new MockTransport(array(new HttpResponse(200, $payload, array())));
    $client = new DeviceConnectClient('https://app.paymos.io', $transport);
    $state = $client->start('woocommerce', 'https://shop.example/');
    assertTrueValue(
        strpos($state['verification_url'], 'return_url=') === false,
        'Callers that pass no return URL must keep the previous approval URL.'
    );
}

function test_device_connect_poll_returns_validated_hmac_credentials()
{
    $config = array(
        'schema' => 1,
        'plugin' => 'woocommerce',
        'source_url' => 'https://shop.example',
        'environments' => array(
            'sandbox' => array(
                'base_url' => 'https://api.paymos.io',
                'api_key' => 'pk_test_123',
                'api_secret' => 'sk_test_123',
                'project_id' => 'prj_123',
                'webhook_secret' => 'whsec_test',
            ),
            'live' => array(
                'base_url' => 'https://api.paymos.io',
                'api_key' => 'pk_live_123',
                'api_secret' => 'sk_live_123',
                'project_id' => 'prj_123',
                'webhook_secret' => 'whsec_live',
            ),
        ),
    );
    $transport = new MockTransport(array(new HttpResponse(200, json_encode(array(
        'access_token' => 'discard-me',
        'token_type' => 'Bearer',
        'paymos_config' => $config,
    )), array())));
    $client = new DeviceConnectClient('https://app.paymos.io', $transport);

    $result = $client->poll('device-secret');

    assertSameValue('connected', $result['status'], 'Successful poll must finish the connection.');
    assertSameValue('sk_test_123', $result['credentials']['sandbox']['api_secret'], 'Existing sandbox secret must be returned.');
    assertSameValue('whsec_live', $result['credentials']['live']['webhook_secret'], 'Live CMS webhook secret must be returned.');
}

function test_device_connect_poll_preserves_pending_state()
{
    $transport = new MockTransport(array(new HttpResponse(400, '{"error":"authorization_pending"}', array())));
    $client = new DeviceConnectClient('https://app.paymos.io', $transport);

    $result = $client->poll('device-secret');

    assertSameValue('authorization_pending', $result['status'], 'Pending authorization must be pollable, not exceptional.');
}
