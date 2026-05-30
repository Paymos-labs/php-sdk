<?php

declare(strict_types=1);

namespace Paymos;

use Paymos\Http\CurlTransport;
use Paymos\Http\RetryPolicy;
use Paymos\Http\RetryingTransport;
use Paymos\Http\TransportInterface;
use Paymos\Resources\Balances;
use Paymos\Resources\Invoices;
use Paymos\Resources\Withdrawals;

final class Client
{
    /** @var ClientConfig */
    private $config;

    /** @var TransportInterface */
    private $transport;

    /** @var callable */
    private $clock;

    public function __construct(ClientConfig $config, TransportInterface $transport = null, callable $clock = null)
    {
        $this->config = $config;
        $this->transport = $transport ?: new RetryingTransport(new CurlTransport(), RetryPolicy::default());
        $this->clock = $clock ?: static function () {
            return time();
        };
    }

    public function invoices()
    {
        return new Invoices($this->config, $this->transport, $this->clock);
    }

    public function withdrawals()
    {
        return new Withdrawals($this->config, $this->transport, $this->clock);
    }

    public function balances()
    {
        return new Balances($this->config, $this->transport, $this->clock);
    }
}
