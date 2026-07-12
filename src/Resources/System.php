<?php

declare(strict_types=1);

namespace Paymos\Resources;

final class System extends BaseResource
{
    /** @return array{server_time: int} */
    public function time()
    {
        return $this->requestJson('GET', '/v1/time', null);
    }
}
