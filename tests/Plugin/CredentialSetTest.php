<?php

declare(strict_types=1);

use Paymos\Plugin\CredentialSet;

function test_cms_credentials_accept_only_the_production_api_origin()
{
    $base = array(
        'api_key' => 'pk_live_123',
        'api_secret' => 'sk_live_123',
        'project_id' => 'prj_123',
        'webhook_secret' => 'whsec_live_123',
    );

    $production = CredentialSet::normalizeEnvironment(
        'live',
        array_merge($base, array('base_url' => 'https://api.paymos.io'))
    );
    assertSameValue(
        'https://api.paymos.io',
        $production['base_url'],
        'Official CMS credentials must accept the production API origin.'
    );

    foreach (array('https://api-dev.paymos.io', 'https://api-local.paymos.io') as $untrustedUrl) {
        $rejected = false;
        try {
            CredentialSet::normalizeEnvironment(
                'live',
                array_merge($base, array('base_url' => $untrustedUrl))
            );
        } catch (\InvalidArgumentException $error) {
            $rejected = $error->getMessage() === 'Paymos API base URL is not trusted.';
        }

        assertTrueValue($rejected, 'Official CMS credentials must reject non-production API origins.');
    }
}

function credentialSetTestValues(array $extra = array())
{
    return array_merge(array(
        'api_key' => 'pk_live_123',
        'api_secret' => 'sk_live_123',
        'project_id' => 'prj_123',
        'webhook_secret' => 'whsec_live_123',
    ), $extra);
}

function test_cms_credentials_preserve_refund_capability_fields()
{
    $values = credentialSetTestValues(array(
        'capabilities' => array('invoice_refunds_v1', 'other_capability'),
        'refund_contract_version' => 1,
        'refunds_enabled' => true,
    ));
    $credentials = CredentialSet::normalizeEnvironment('live', $values);

    assertSameValue($values['capabilities'], $credentials['capabilities'], 'All string capabilities must be retained.');
    assertSameValue(1, $credentials['refund_contract_version'], 'Refund contract version must remain an integer.');
    assertSameValue(true, $credentials['refunds_enabled'], 'The create gate must remain a boolean.');
    assertTrueValue(CredentialSet::hasCapability($credentials, 'invoice_refunds_v1'), 'The declared refund capability must be discoverable.');
    assertTrueValue(CredentialSet::refundsEnabled($credentials), 'The create gate getter must return true.');
    foreach (credentialSetTestValues() as $field => $value) {
        assertSameValue($value, $credentials[$field], 'Existing credential fields must be retained.');
    }
    assertSameValue($credentials, CredentialSet::normalizeEnvironment('live', $credentials), 'Stored credentials must retain the handshake when normalized again.');
}

function test_cms_credentials_default_refund_fields_for_older_servers()
{
    $credentials = CredentialSet::normalizeEnvironment('live', credentialSetTestValues());

    assertSameValue(array(), $credentials['capabilities'], 'An older server must default to no capabilities.');
    assertSameValue(null, $credentials['refund_contract_version'], 'An older server must have no refund contract version.');
    assertSameValue(false, $credentials['refunds_enabled'], 'An older server must default to a closed create gate.');
    assertFalseValue(CredentialSet::hasCapability($credentials, 'invoice_refunds_v1'), 'Old credentials must require a capability handshake.');
    assertFalseValue(CredentialSet::hasCapability(array(), 'invoice_refunds_v1'), 'Missing capability metadata must be safe to query.');
    assertFalseValue(CredentialSet::refundsEnabled(array()), 'Missing create gate metadata must default to false.');
}

function test_cms_credentials_keep_refund_capability_when_create_is_disabled()
{
    $credentials = CredentialSet::normalizeEnvironment('live', credentialSetTestValues(array(
        'capabilities' => array('invoice_refunds_v1'),
        'refund_contract_version' => 1,
        'refunds_enabled' => false,
    )));

    assertSameValue(false, $credentials['refunds_enabled'], 'A disabled create gate must remain false.');
    assertFalseValue(CredentialSet::refundsEnabled($credentials), 'The getter must respect a disabled create gate.');
    assertTrueValue(CredentialSet::hasCapability($credentials, 'invoice_refunds_v1'), 'A disabled create gate must preserve the read and reconciliation capability.');
    assertSameValue(1, $credentials['refund_contract_version'], 'A disabled create gate must preserve the contract version.');
}

function test_cms_credentials_require_the_exact_refund_capability()
{
    $values = credentialSetTestValues(array(
        'capabilities' => array('other_capability', 'invoice_refunds_v2', 'invoice_refunds_v1_extra', 'INVOICE_REFUNDS_V1'),
        'refund_contract_version' => 1,
        'refunds_enabled' => true,
    ));
    $credentials = CredentialSet::normalizeEnvironment('live', $values);

    assertSameValue($values['capabilities'], $credentials['capabilities'], 'Unrelated capabilities must be retained.');
    assertFalseValue(CredentialSet::hasCapability($credentials, 'invoice_refunds_v1'), 'Unrelated capabilities and an open create gate must not imply the refund contract.');
    assertTrueValue(CredentialSet::hasCapability($credentials, 'other_capability'), 'Capability lookup must support other declared contracts.');
    assertFalseValue(CredentialSet::hasCapability(array('capabilities' => array(1)), '1'), 'Capability lookup must use strict equality.');
}

function test_cms_credentials_keep_only_string_capabilities()
{
    $credentials = CredentialSet::normalizeEnvironment('live', credentialSetTestValues(array(
        'capabilities' => array(2 => 'other_capability', 4 => false, 5 => 1, 7 => null, 8 => array('invoice_refunds_v1'), 9 => 'invoice_refunds_v1'),
    )));

    assertSameValue(array('other_capability', 'invoice_refunds_v1'), $credentials['capabilities'], 'Capabilities must be a list of strings.');
}

function test_cms_credentials_default_malformed_refund_metadata()
{
    foreach (array(
        array('capabilities' => 'invoice_refunds_v1', 'refund_contract_version' => '1', 'refunds_enabled' => 'true'),
        array('capabilities' => true, 'refund_contract_version' => true, 'refunds_enabled' => 'false'),
        array('capabilities' => null, 'refund_contract_version' => 1.0, 'refunds_enabled' => 1),
        array('capabilities' => 1, 'refund_contract_version' => array(1), 'refunds_enabled' => array(true)),
    ) as $metadata) {
        $credentials = CredentialSet::normalizeEnvironment('live', credentialSetTestValues($metadata));

        assertSameValue(array(), $credentials['capabilities'], 'Malformed capabilities must default to an empty list.');
        assertSameValue(null, $credentials['refund_contract_version'], 'A non-integer version must default to null.');
        assertSameValue(false, $credentials['refunds_enabled'], 'Only a boolean true may open the create gate.');
        assertFalseValue(CredentialSet::hasCapability($credentials, 'invoice_refunds_v1'), 'Malformed metadata must not confirm the refund contract.');
        assertFalseValue(CredentialSet::refundsEnabled($metadata), 'The getter must also reject malformed create gates.');
    }
}
