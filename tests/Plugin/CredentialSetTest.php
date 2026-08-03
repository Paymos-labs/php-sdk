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
