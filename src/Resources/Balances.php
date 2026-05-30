<?php

declare(strict_types=1);

namespace Paymos\Resources;

final class Balances extends BaseResource
{
    private const PATH = '/v1/balances';

    /**
     * @return array
     */
    public function get()
    {
        return $this->requestJson('GET', self::PATH, null);
    }
}
