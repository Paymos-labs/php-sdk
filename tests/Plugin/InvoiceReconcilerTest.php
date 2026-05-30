<?php

declare(strict_types=1);

use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Http\HttpResponse;
use Paymos\Http\MockTransport;
use Paymos\Plugin\InvoiceReconciler;
use Paymos\Plugin\StatusMapper;

function test_invoice_reconciler_fetches_invoices_and_maps_actions()
{
    $transport = new MockTransport(array(
        new HttpResponse(200, json_encode(array('invoice_id' => 'inv_paid', 'status' => 'paid')), array()),
        new HttpResponse(200, json_encode(array('invoice_id' => 'inv_expired', 'status' => 'expired')), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_abc', 'sk_test_def', 'https://api.paymos.test'), $transport);

    $results = (new InvoiceReconciler($client))->reconcile(array('inv_paid', 'inv_expired'));

    assertSameValue('inv_paid', $results[0]->invoiceId(), 'first reconciliation result must keep invoice id.');
    assertSameValue('paid', $results[0]->status(), 'first result must expose fetched status.');
    assertSameValue(StatusMapper::ACTION_PAYMENT_COMPLETE, $results[0]->action(), 'paid invoice must map to payment completion.');
    assertSameValue(StatusMapper::ACTION_CANCEL_ORDER, $results[1]->action(), 'expired invoice must map to order cancellation.');
}
