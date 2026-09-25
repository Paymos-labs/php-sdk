<?php

declare(strict_types=1);

use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Http\HttpResponse;
use Paymos\Http\MockTransport;
use Paymos\Plugin\InvoiceReplacement;
use Paymos\Plugin\InvoiceReplacementBlockedException;
use Paymos\Plugin\InvoiceReplacementResult;

// Server contract (src/): POST /v1/invoices/{id}/cancel moves only an
// awaiting_client invoice to cancelled (Invoice.Cancel → InvalidTransition
// otherwise); a repeat cancel of a cancelled invoice is a 200 no-op; every
// other status answers 409 invoice_cannot_be_cancelled (CancelInvoiceHandler).
// A 404 is the same for a missing, foreign or other-environment invoice
// (AuthorizationBehavior: OwnershipViolationException → not_found).

function replacement_client(array $responses, &$transport = null)
{
    $transport = new MockTransport($responses);

    return new Client(new ClientConfig('pk_test_abc', 'sk_test_def', 'https://api.paymos.test'), $transport, static function () {
        return 1709000000;
    });
}

function replacement_invoice($status, $invoiceId = 'inv_old')
{
    return new HttpResponse(200, json_encode(array(
        'invoice_id' => $invoiceId,
        'project_id' => 'prj_123',
        'status' => $status,
        'is_final' => in_array($status, array('paid', 'paid_over', 'underpaid', 'expired', 'cancelled'), true),
        'order' => array('external_id' => 'oc_42_0', 'amount' => '50', 'currency' => 'USD'),
    )), array());
}

function replacement_problem($status, $code, $detail)
{
    return new HttpResponse($status, json_encode(array(
        'type' => 'https://paymos.io/errors/' . $code,
        'title' => 'Error',
        'status' => $status,
        'detail' => $detail,
        'code' => $code,
    )), array('Content-Type' => 'application/problem+json'));
}

function replacement_cannot_cancel($status)
{
    return replacement_problem(409, 'invoice_cannot_be_cancelled', 'Invoice cannot be cancelled in status ' . $status . '. Only invoices awaiting client can be cancelled.');
}

function test_invoice_replacement_cancels_an_unstarted_invoice()
{
    $client = replacement_client(array(replacement_invoice('cancelled')), $transport);

    $result = (new InvoiceReplacement($client))->close('inv_old');

    assertTrueValue($result->isClosed(), 'a cancelled invoice may be replaced.');
    assertSameValue(InvoiceReplacementResult::REASON_CANCELLED, $result->reason(), 'the reason says the plugin cancelled it.');
    assertSameValue('cancelled', $result->status(), 'the server status is exposed.');
    assertSameValue('inv_old', $result->invoiceId(), 'the result names the old invoice.');
    $requests = $transport->requests();
    assertSameValue(1, count($requests), 'a successful cancel needs no read.');
    assertSameValue('POST', $requests[0]['method'], 'cancel is a POST.');
    assertSameValue('https://api.paymos.test/v1/invoices/inv_old/cancel', $requests[0]['url'], 'the public cancel endpoint is used.');
    $body = json_decode($requests[0]['body'], true);
    assertTrueValue(is_string($body['reason']) && trim($body['reason']) !== '' && strlen($body['reason']) <= 500, 'the server requires a reason of 1..500 characters.');
}

function test_invoice_replacement_replaces_an_invoice_the_server_says_ended_unpaid()
{
    foreach (array('expired', 'cancelled', 'underpaid') as $status) {
        $client = replacement_client(array(replacement_cannot_cancel($status), replacement_invoice($status)), $transport);

        $result = (new InvoiceReplacement($client))->close('inv_old');

        assertTrueValue($result->isClosed(), $status . ' can no longer be paid, so it may be replaced.');
        assertSameValue(InvoiceReplacementResult::REASON_ENDED_UNPAID, $result->reason(), $status . ' ended without a full payment.');
        assertSameValue($status, $result->status(), 'the server status is exposed for ' . $status . '.');
        assertSameValue(2, count($transport->requests()), 'a refused cancel is followed by one read for ' . $status . '.');
        assertSameValue('GET', $transport->requests()[1]['method'], 'the second call is a read for ' . $status . '.');
    }
}

function test_invoice_replacement_keeps_an_invoice_the_buyer_can_still_pay()
{
    // Network picked, funds confirming, part paid: the cancel guard refuses
    // (only awaiting_client is cancellable), and a second invoice next to it
    // would invite a second payment.
    foreach (array('awaiting_payment', 'confirming', 'underpaid_waiting') as $status) {
        $client = replacement_client(array(replacement_cannot_cancel($status), replacement_invoice($status)));

        $result = (new InvoiceReplacement($client))->close('inv_old');

        assertFalseValue($result->isClosed(), $status . ' must not be replaced.');
        assertSameValue(InvoiceReplacementResult::REASON_OPEN, $result->reason(), $status . ' is still open.');
        assertSameValue($status, $result->status(), 'the server status is exposed for ' . $status . '.');
        assertTrueValue(strpos($result->summary(), 'inv_old') !== false, 'the summary names the invoice for ' . $status . '.');
        assertTrueValue(strpos(str_replace('inv_old', '', $result->summary()), '_') === false && stripos($result->summary(), 'status') === false, 'the summary says the state in words, not as the API status ' . $status . '.');
        assertTrueValue(strpos($result->summary(), 'no new invoice was issued') !== false, 'the summary says no new invoice was issued for ' . $status . '.');
    }

    $summaries = array();
    foreach (array('awaiting_payment', 'confirming', 'underpaid_waiting') as $status) {
        $summaries[] = InvoiceReplacementResult::blocked('inv_old', $status, InvoiceReplacementResult::REASON_OPEN)->summary();
    }
    assertSameValue(3, count(array_unique($summaries)), 'each open state reads differently in the order note.');
}

function test_invoice_replacement_summaries_carry_no_internals()
{
    // The summary lands in merchant order notes: no HTTP codes, no API status
    // names, no error text from the transport.
    $results = array(
        InvoiceReplacementResult::closed('inv_old', 'cancelled', InvoiceReplacementResult::REASON_CANCELLED),
        InvoiceReplacementResult::closed('inv_old', 'expired', InvoiceReplacementResult::REASON_ENDED_UNPAID),
        InvoiceReplacementResult::blocked('inv_old', 'paid_over', InvoiceReplacementResult::REASON_PAID),
        InvoiceReplacementResult::blocked('inv_old', 'awaiting_client', InvoiceReplacementResult::REASON_OPEN),
        InvoiceReplacementResult::blocked('inv_old', '', InvoiceReplacementResult::REASON_NOT_FOUND, 'Paymos API 404 not_found: Invoice not found.'),
        InvoiceReplacementResult::blocked('inv_old', '', InvoiceReplacementResult::REASON_UNREACHABLE, 'Paymos API 503 unavailable: Service unavailable.'),
        InvoiceReplacementResult::blocked('', '', InvoiceReplacementResult::REASON_MISSING_ID),
    );

    foreach ($results as $result) {
        $summary = $result->summary();
        assertTrueValue($summary !== '', $result->reason() . ': the summary is not empty.');
        assertTrueValue(preg_match('/\b[45]\d\d\b/', $summary) === 0, $result->reason() . ': the summary carries no HTTP code.');
        assertTrueValue(strpos(str_replace('inv_old', '', $summary), '_') === false, $result->reason() . ': the summary carries no API status name.');
        assertTrueValue(strpos($summary, 'Paymos API') === false, $result->reason() . ': the summary carries no transport error text.');
    }

    assertTrueValue(strpos($results[6]->summary(), 'Paymos invoice ,') === false && strpos($results[6]->summary(), 'Paymos invoice  ') === false, 'without an id the summary does not print an empty invoice id.');
    assertTrueValue(strpos(InvoiceReplacementResult::blocked('', '', InvoiceReplacementResult::REASON_UNREACHABLE)->summary(), 'The previous Paymos invoice') === 0, 'an unreachable invoice without an id is named in words.');
}

function test_invoice_replacement_never_replaces_a_paid_invoice()
{
    foreach (array('paid', 'paid_over') as $status) {
        $client = replacement_client(array(replacement_cannot_cancel($status), replacement_invoice($status)));

        $result = (new InvoiceReplacement($client))->close('inv_old');

        assertFalseValue($result->isClosed(), $status . ' must never be replaced.');
        assertSameValue(InvoiceReplacementResult::REASON_PAID, $result->reason(), $status . ' is paid.');
    }
}

function test_invoice_replacement_treats_404_as_not_closed()
{
    // A foreign or other-environment invoice answers exactly like a missing
    // one, and a plugin only holds ids the server once issued to it: a 404
    // proves nothing about whether the invoice can still be paid.
    $client = replacement_client(array(replacement_problem(404, 'not_found', 'Invoice not found.')), $transport);

    $result = (new InvoiceReplacement($client))->close('inv_old');

    assertFalseValue($result->isClosed(), 'a 404 must not let a replacement through.');
    assertSameValue(InvoiceReplacementResult::REASON_NOT_FOUND, $result->reason(), 'the reason is the 404.');
    assertSameValue('', $result->status(), 'no status is known.');
    assertSameValue(1, count($transport->requests()), 'the read would answer 404 just the same.');
}

function test_invoice_replacement_treats_404_on_the_read_as_not_closed()
{
    $client = replacement_client(array(replacement_cannot_cancel('expired'), replacement_problem(404, 'not_found', 'Invoice not found.')));

    $result = (new InvoiceReplacement($client))->close('inv_old');

    assertFalseValue($result->isClosed(), 'a 404 on the read must not let a replacement through.');
    assertSameValue(InvoiceReplacementResult::REASON_NOT_FOUND, $result->reason(), 'the reason is the 404.');
}

function test_invoice_replacement_is_not_closed_when_the_server_cannot_be_reached()
{
    // Cancel fails, then the read fails too (no queued response → transport error).
    $client = replacement_client(array(replacement_problem(503, 'unavailable', 'Service unavailable.')));

    $result = (new InvoiceReplacement($client))->close('inv_old');

    assertFalseValue($result->isClosed(), 'an unanswered server must not let a replacement through.');
    assertSameValue(InvoiceReplacementResult::REASON_UNREACHABLE, $result->reason(), 'the reason is the failure.');
    assertTrueValue($result->detail() !== '', 'the failure is kept for the log.');
}

function test_invoice_replacement_accepts_the_read_when_cancel_failed_for_another_reason()
{
    // The cancel call itself failed (5xx), but the server then says the invoice
    // expired: that answer is enough.
    $client = replacement_client(array(replacement_problem(500, 'internal_error', 'Boom.'), replacement_invoice('expired')));

    $result = (new InvoiceReplacement($client))->close('inv_old');

    assertTrueValue($result->isClosed(), 'the server said the invoice ended unpaid.');
    assertSameValue(InvoiceReplacementResult::REASON_ENDED_UNPAID, $result->reason(), 'the reason comes from the read.');
}

function test_invoice_replacement_does_not_trust_a_cancel_answer_that_is_not_cancelled()
{
    // Not reachable on today's server (cancel answers the cancelled invoice),
    // but a 2xx that does not say "cancelled" proves nothing: read it back.
    $client = replacement_client(array(replacement_invoice('awaiting_client'), replacement_invoice('awaiting_client')), $transport);

    $result = (new InvoiceReplacement($client))->close('inv_old');

    assertFalseValue($result->isClosed(), 'an invoice still awaiting the client is open.');
    assertSameValue(InvoiceReplacementResult::REASON_OPEN, $result->reason(), 'awaiting_client is open.');
    assertSameValue(2, count($transport->requests()), 'the ambiguous answer is read back once.');
}

function test_invoice_replacement_without_an_invoice_id_is_not_closed()
{
    $client = replacement_client(array(), $transport);

    $result = (new InvoiceReplacement($client))->close('  ');

    assertFalseValue($result->isClosed(), 'without an id nothing can be proven closed.');
    assertSameValue(InvoiceReplacementResult::REASON_MISSING_ID, $result->reason(), 'the reason is the missing id.');
    assertSameValue(0, count($transport->requests()), 'nothing is sent without an id.');
}

function test_invoice_replacement_blocked_exception_carries_the_result()
{
    $client = replacement_client(array(replacement_cannot_cancel('confirming'), replacement_invoice('confirming')));
    $result = (new InvoiceReplacement($client))->close('inv_old');

    $exception = new InvoiceReplacementBlockedException($result);

    assertTrueValue($exception instanceof \RuntimeException, 'plugins already catch RuntimeException at checkout.');
    assertSameValue($result, $exception->result(), 'the exception carries the result.');
    assertTrueValue(strpos($exception->getMessage(), 'inv_old') === false, 'the message may reach the buyer, so it names no invoice id.');
    assertTrueValue(stripos($exception->getMessage(), 'invoice') === false, 'the buyer is not told about invoices.');
    assertTrueValue(stripos($exception->getMessage(), 'contact the store') !== false, 'the buyer is told what to do.');
}

function test_invoice_replacement_trusts_a_final_status_the_plugin_recorded_from_the_server()
{
    // Final statuses never change on the server (Invoice.IsTerminal), and a
    // plugin records one only from a reverse-verified webhook or a read.
    foreach (array('expired', 'cancelled', 'underpaid') as $status) {
        $client = replacement_client(array(), $transport);

        $result = (new InvoiceReplacement($client))->close('inv_old', $status);

        assertTrueValue($result->isClosed(), 'a recorded ' . $status . ' may be replaced.');
        assertSameValue(InvoiceReplacementResult::REASON_ENDED_UNPAID, $result->reason(), 'a recorded ' . $status . ' ended unpaid.');
        assertSameValue(0, count($transport->requests()), 'a recorded ' . $status . ' needs no call.');
    }

    foreach (array('paid', 'PAID_OVER') as $status) {
        $client = replacement_client(array(), $transport);

        $result = (new InvoiceReplacement($client))->close('inv_old', $status);

        assertFalseValue($result->isClosed(), 'a recorded ' . $status . ' is never replaced.');
        assertSameValue(InvoiceReplacementResult::REASON_PAID, $result->reason(), 'a recorded ' . $status . ' is paid.');
        assertSameValue(0, count($transport->requests()), 'a recorded ' . $status . ' needs no call.');
    }
}

function test_invoice_replacement_asks_the_server_about_a_recorded_open_status()
{
    foreach (array('awaiting_client', 'awaiting_payment', 'created', '') as $status) {
        $client = replacement_client(array(replacement_invoice('cancelled')), $transport);

        $result = (new InvoiceReplacement($client))->close('inv_old', $status);

        assertTrueValue($result->isClosed(), 'recorded ' . var_export($status, true) . ': the server cancelled it.');
        assertSameValue(1, count($transport->requests()), 'recorded ' . var_export($status, true) . ' is not final, so the server is asked.');
    }
}

function test_invoice_replacement_builds_the_client_only_when_it_needs_one()
{
    $calls = 0;
    $result = (new InvoiceReplacement(static function () use (&$calls) {
        $calls++;
        throw new \InvalidArgumentException('Paymos live API key is missing.');
    }))->close('inv_old', 'expired');

    assertTrueValue($result->isClosed(), 'a recorded final status needs no client.');
    assertSameValue(0, $calls, 'the factory is not called when no request is needed.');
}

function test_invoice_replacement_is_not_closed_when_the_client_cannot_be_built()
{
    // The old invoice lives in an environment whose credentials are gone.
    $result = (new InvoiceReplacement(static function () {
        throw new \InvalidArgumentException('Paymos live API key is missing.');
    }))->close('inv_old', 'awaiting_client');

    assertFalseValue($result->isClosed(), 'without a client nothing can be proven closed.');
    assertSameValue(InvoiceReplacementResult::REASON_UNREACHABLE, $result->reason(), 'the reason is the failure.');
    assertTrueValue(strpos($result->detail(), 'live API key') !== false, 'the factory error is kept for the log.');
}

function test_invoice_replacement_accepts_a_client_factory()
{
    $client = replacement_client(array(replacement_invoice('cancelled')), $transport);
    $calls = 0;

    $result = (new InvoiceReplacement(static function () use ($client, &$calls) {
        $calls++;
        return $client;
    }))->close('inv_old');

    assertTrueValue($result->isClosed(), 'the factory-built client cancels the invoice.');
    assertSameValue(1, $calls, 'the factory is called once.');
}
