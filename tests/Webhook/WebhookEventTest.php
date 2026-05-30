<?php

declare(strict_types=1);

use Paymos\Webhook\WebhookEvent;

function test_webhook_event_exposes_invoice_payload_fields()
{
    $event = new WebhookEvent(array(
        'event_id' => 'evt_123',
        'event_type' => 'invoice.paid',
        'data' => array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'paid',
            'is_test' => 'true',
            'order' => array(
                'external_id' => 'wc_100',
                'amount' => '49.95',
                'currency' => 'usd',
            ),
        ),
    ));

    assertSameValue('evt_123', $event->id(), 'event id must be exposed.');
    assertSameValue('invoice.paid', $event->type(), 'event type must be exposed.');
    assertSameValue(true, $event->isInvoiceEvent(), 'invoice.* must be recognized as invoice event.');
    assertSameValue('inv_123', $event->invoiceId(), 'invoice id must be read from data.invoice_id.');
    assertSameValue('prj_123', $event->projectId(), 'project id must be read from data.project_id.');
    assertSameValue('paid', $event->status(), 'status must be read from data.status.');
    assertSameValue(true, $event->isTest(), 'string true must be normalized to bool true.');
    assertSameValue('wc_100', $event->externalOrderId(), 'external order id must be read from data.order.external_id.');
    assertSameValue('49.95', $event->orderAmount(), 'order amount must be read from data.order.amount.');
    assertSameValue('usd', $event->orderCurrency(), 'order currency must be read from data.order.currency.');
}

function test_webhook_event_returns_empty_strings_for_missing_scalar_fields()
{
    $event = new WebhookEvent(array('event_id' => 'evt_123', 'event_type' => 'invoice.created'));

    assertSameValue('', $event->invoiceId(), 'missing invoice id must return empty string.');
    assertSameValue('', $event->projectId(), 'missing project id must return empty string.');
    assertSameValue('', $event->externalOrderId(), 'missing external order id must return empty string.');
    assertSameValue('', $event->orderAmount(), 'missing order amount must return empty string.');
    assertSameValue('', $event->orderCurrency(), 'missing order currency must return empty string.');
    assertSameValue(null, $event->isTest(), 'missing is_test must return null.');
}
