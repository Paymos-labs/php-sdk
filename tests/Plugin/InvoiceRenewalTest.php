<?php

declare(strict_types=1);

use Paymos\Plugin\InvoiceRenewal;

function test_invoice_renewal_keeps_an_open_invoice()
{
    $now = 1709000000;
    assertSameValue(false, InvoiceRenewal::isRequired(array('status' => 'awaiting_client', 'is_final' => false, 'expires_at' => $now + 60), $now), 'an unexpired awaiting_client invoice is kept.');
    assertSameValue(false, InvoiceRenewal::isRequired(array('status' => 'awaiting_payment', 'expires_at' => $now - 60), $now), 'a buyer who picked a token keeps the invoice until the server closes it.');
    assertSameValue(false, InvoiceRenewal::isRequired(array('status' => 'confirming', 'expires_at' => $now - 60), $now), 'funds in flight keep the invoice.');
    assertSameValue(false, InvoiceRenewal::isRequired(array('status' => 'underpaid_waiting'), $now), 'a partly paid invoice stays open for the rest.');
    assertSameValue(false, InvoiceRenewal::isRequired(array('status' => 'created'), $now), 'a plugin placeholder without an expiry is kept.');
}

function test_invoice_renewal_never_renews_a_paid_invoice()
{
    // Final, but settled: a second invoice would invite a second payment.
    foreach (array('paid', 'paid_over') as $status) {
        assertSameValue(false, InvoiceRenewal::isRequired(array('status' => $status, 'is_final' => true, 'expires_at' => 1), 1709000000), $status . ' must never be renewed.');
    }
}

function test_invoice_renewal_renews_an_invoice_that_ended_unpaid()
{
    foreach (array('underpaid', 'expired', 'cancelled', 'EXPIRED') as $status) {
        assertSameValue(true, InvoiceRenewal::isRequired(array('status' => $status), 1709000000), $status . ' must be renewed.');
    }
}

function test_invoice_renewal_renews_an_unstarted_invoice_past_its_deadline()
{
    // InvoiceOptions.PaymentTtl: past expires_at the server refuses
    // confirm-payment (Invoice.ConfirmPayment → InvoiceExpiredError) even
    // before ClientExpiryService flips the status. The deadline is the
    // server's; it only counts once it lies further back than any clock
    // difference the webhook tolerance already allows.
    $now = 1709000000;
    $skew = 300;
    assertSameValue(true, InvoiceRenewal::isRequired(array('status' => 'awaiting_client', 'expires_at' => $now - $skew), $now), 'a deadline one full skew allowance back has passed on the server too.');
    assertSameValue(true, InvoiceRenewal::isRequired(array('status' => 'awaiting_client', 'expires_at' => (string) ($now - $skew - 1)), $now), 'a numeric-string expires_at is read too.');
}

function test_invoice_renewal_does_not_trust_a_deadline_within_the_clock_skew()
{
    // BUG-163: the store's clock may run ahead of the server's. An invoice
    // whose deadline passed only by the store's clock can still be confirmed
    // on the server, and a second invoice next to it invites a second payment.
    $now = 1709000000;
    foreach (array(0, 1, 60, 299) as $ago) {
        assertSameValue(false, InvoiceRenewal::isRequired(array('status' => 'awaiting_client', 'is_final' => false, 'expires_at' => $now - $ago), $now), 'a deadline ' . $ago . ' s back by the store clock is not yet past on the server.');
    }
    assertSameValue(300, InvoiceRenewal::CLOCK_SKEW_SECONDS, 'the allowance matches the webhook timestamp tolerance (WebhookVerifier, 300 s).');
}

function test_invoice_renewal_keeps_an_invoice_the_server_holds_open_past_the_old_deadline()
{
    // BUG-163: confirming a network moves expires_at to now + PaymentTtl and
    // sends no webhook. The server's answer (API shape) is what counts: a
    // chosen network, funds confirming or a part payment is never renewed,
    // however far back the deadline a plugin remembers lies.
    $now = 1709000000;
    foreach (array('awaiting_payment', 'confirming', 'underpaid_waiting') as $status) {
        assertSameValue(false, InvoiceRenewal::isRequired(array('status' => $status, 'is_final' => false, 'expires_at' => $now - 86400), $now), $status . ' is still open on the server and must not be replaced.');
    }
}
