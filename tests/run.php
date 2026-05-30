<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$tests = array(
    __DIR__ . '/Http/RequestSignerTest.php',
    __DIR__ . '/Http/RetryingTransportTest.php',
    __DIR__ . '/Webhook/WebhookEventTest.php',
    __DIR__ . '/Webhook/WebhookVerifierTest.php',
    __DIR__ . '/Webhook/WebhookEventProcessorTest.php',
    __DIR__ . '/Webhook/MultiEnvironmentWebhookVerifierTest.php',
    __DIR__ . '/Resources/InvoicesResourceTest.php',
    __DIR__ . '/Resources/WithdrawalsResourceTest.php',
    __DIR__ . '/Resources/BalancesResourceTest.php',
    __DIR__ . '/Exception/ApiExceptionTest.php',
    __DIR__ . '/Plugin/StatusMapperTest.php',
    __DIR__ . '/Plugin/AmountGuardTest.php',
    __DIR__ . '/Plugin/InvoiceReverseVerifierTest.php',
    __DIR__ . '/Plugin/InvoiceReconcilerTest.php',
    __DIR__ . '/IdempotencyKeyTest.php',
    __DIR__ . '/ClientConfigTest.php',
);

$count = 0;

foreach ($tests as $test) {
    require $test;
}

foreach (get_defined_functions()['user'] as $function) {
    if (strpos($function, 'test_') !== 0) {
        continue;
    }

    $function();
    $count++;
    echo "PASS {$function}\n";
}

echo "OK {$count} tests\n";
