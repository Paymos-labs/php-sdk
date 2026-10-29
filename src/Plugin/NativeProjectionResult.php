<?php

declare(strict_types=1);

namespace Paymos\Plugin;

final class NativeProjectionResult
{
    public const ABSENT = 'absent';
    public const EXACT_MATCH = 'exact_match';
    public const AMBIGUOUS = 'ambiguous';

    /** @var array<string, mixed> */
    private $data;

    public function __construct(string $kind, string $mode, ?string $nativeId = null,
        ?string $nativeAmountMinor = null, ?string $nativeCurrency = null,
        ?string $cryptoObservationAmount = null, ?string $cryptoAsset = null, ?string $reason = null)
    {
        if (!in_array($kind, array(self::ABSENT, self::EXACT_MATCH, self::AMBIGUOUS), true)
            || !in_array($mode, array(RefundStates::FULL, RefundStates::OVERPAYMENT), true)) {
            throw new \InvalidArgumentException('Invalid native projection result.');
        }
        $this->data = compact('kind', 'mode', 'nativeId', 'nativeAmountMinor', 'nativeCurrency',
            'cryptoObservationAmount', 'cryptoAsset', 'reason');
    }

    public function kind(): string { return $this->data['kind']; }
    public function mode(): string { return $this->data['mode']; }
    public function nativeId(): ?string { return $this->data['nativeId']; }
    public function nativeAmountMinor(): ?string { return $this->data['nativeAmountMinor']; }
    public function nativeCurrency(): ?string { return $this->data['nativeCurrency']; }
    public function cryptoObservationAmount(): ?string { return $this->data['cryptoObservationAmount']; }
    public function cryptoAsset(): ?string { return $this->data['cryptoAsset']; }
    public function reason(): ?string { return $this->data['reason']; }
    public function toArray(): array { return $this->data; }

    public static function fromStorage(array $data): self
    {
        return new self($data['kind'], $data['mode'], $data['nativeId'], $data['nativeAmountMinor'],
            $data['nativeCurrency'], $data['cryptoObservationAmount'], $data['cryptoAsset'], $data['reason']);
    }
}
