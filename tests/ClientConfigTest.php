<?php

declare(strict_types=1);

use Paymos\ClientConfig;

function test_client_config_detects_sandbox_from_pk_test_key()
{
    $cfg = new ClientConfig('pk_test_aaa', 'sk_test_bbb');
    assertTrueValue($cfg->isSandbox(), 'pk_test_… key must be classified as sandbox.');
}

function test_client_config_detects_sandbox_from_rk_test_key()
{
    $cfg = new ClientConfig('rk_test_aaa', 'sk_test_bbb');
    assertTrueValue($cfg->isSandbox(), 'rk_test_… key must be classified as sandbox.');
}

function test_client_config_detects_production_from_live_key()
{
    $cfg = new ClientConfig('pk_live_aaa', 'sk_live_bbb');
    assertFalseValue($cfg->isSandbox(), 'pk_live_… key must be classified as production.');
}

function test_client_config_rejects_empty_credentials()
{
    $threw = false;
    try {
        new ClientConfig('', 'sk_test_bbb');
    } catch (\InvalidArgumentException $e) {
        $threw = true;
    }
    assertTrueValue($threw, 'Empty apiKey must be rejected.');

    $threw = false;
    try {
        new ClientConfig('pk_test_aaa', '');
    } catch (\InvalidArgumentException $e) {
        $threw = true;
    }
    assertTrueValue($threw, 'Empty apiSecret must be rejected.');
}

function test_client_config_rejects_non_positive_timeout()
{
    $threw = false;
    try {
        new ClientConfig('pk_test_aaa', 'sk_test_bbb', 'https://api.paymos.io', 0);
    } catch (\InvalidArgumentException $e) {
        $threw = true;
    }
    assertTrueValue($threw, 'Zero timeout must be rejected.');
}
