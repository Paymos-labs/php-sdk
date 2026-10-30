<?php

declare(strict_types=1);

namespace Paymos\Plugin;

use Paymos\Client;
use Paymos\Exception\ApiException;

/** One bounded, read-only page. The caller persists the cursor and verifies each mapped refund independently. */
final class RefundDiscovery
{
    public static function page(Client $client, string $environment, ?string $cursor, callable $observe): array
    {
        if (!in_array($environment, array('sandbox', 'live'), true)) {
            throw new \InvalidArgumentException('refund_environment_invalid');
        }
        $filters = array('limit' => 50);
        if ($cursor !== null && $cursor !== '') { $filters['cursor'] = $cursor; }
        try { $page = $client->refunds()->listPage($filters); }
        catch (ApiException $error) {
            if ($cursor !== null && $cursor !== '' && $error->statusCode() === 400
                && $error->errorCode() === 'pagination_cursor_invalid') {
                return array('next_cursor' => null, 'checked' => 0, 'errors' => array('pagination_cursor_invalid'), 'cursor_reset' => true);
            }
            throw $error;
        }
        if (!isset($page['items']) || !is_array($page['items']) || !array_key_exists('next_cursor', $page)
            || ($page['next_cursor'] !== null && (!is_string($page['next_cursor']) || $page['next_cursor'] === '' || $page['next_cursor'] === $cursor))) {
            throw new \UnexpectedValueException('refund_discovery_page_invalid');
        }
        $errors = array();
        $count = 0;
        foreach ($page['items'] as $signal) {
            try {
                if (!is_array($signal)) { throw new \UnexpectedValueException('refund_discovery_scope_invalid'); }
                foreach (array('withdrawal_id', 'invoice_id', 'project_id', 'mode', 'external_order_id') as $field) {
                    if (!isset($signal[$field]) || !is_string($signal[$field]) || $signal[$field] === '') {
                        throw new \UnexpectedValueException('refund_discovery_scope_invalid');
                    }
                }
                if (($signal['is_test'] ?? null) !== ($environment === 'sandbox')
                    || !in_array($signal['mode'], array('full', 'overpayment'), true)) {
                    throw new \UnexpectedValueException('refund_discovery_scope_invalid');
                }
                if ($observe($signal, $environment) !== false) { $count++; }
            } catch (\Throwable $error) {
                // Keep rotating to prevent an unrelated or broken mapping starving other orders.
                // The next complete history sweep retries this same ID; no money is submitted here.
                $errors[] = is_array($signal) && isset($signal['withdrawal_id']) && is_string($signal['withdrawal_id'])
                    ? $signal['withdrawal_id'] : 'invalid_item';
            }
        }
        return array('next_cursor' => $page['next_cursor'], 'checked' => $count, 'errors' => $errors);
    }
}
