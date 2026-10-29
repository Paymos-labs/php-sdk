<?php

declare(strict_types=1);

namespace Paymos\Plugin;

final class RefundIntentPage
{
    /** @var RefundIntent[] */
    private $items;
    /** @var string|null */
    private $nextCursor;

    /** @param RefundIntent[] $items */
    public function __construct(array $items, ?string $nextCursor)
    {
        $this->items = $items;
        $this->nextCursor = $nextCursor;
    }

    /** @return RefundIntent[] */
    public function items(): array { return $this->items; }
    public function nextCursor(): ?string { return $this->nextCursor; }
}
