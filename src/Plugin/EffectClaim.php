<?php

declare(strict_types=1);

namespace Paymos\Plugin;

final class EffectClaim
{
    public const CLAIMED = 'claimed';
    public const ALREADY_STARTED = 'already_started';

    /** @var string */
    private $kind;

    public function __construct(string $kind)
    {
        if (!in_array($kind, array(self::CLAIMED, self::ALREADY_STARTED), true)) {
            throw new \InvalidArgumentException('Invalid refund effect claim.');
        }
        $this->kind = $kind;
    }

    public function kind(): string { return $this->kind; }
}
