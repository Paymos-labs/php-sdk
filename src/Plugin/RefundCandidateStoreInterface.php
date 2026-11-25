<?php

declare(strict_types=1);

namespace Paymos\Plugin;

/**
 * Optional store capability for persisting unverified candidate withdrawal evidence
 * returned by an accepted POST when reverse-read is temporarily unavailable (BUG-1347).
 * Candidate metadata is never an authoritative withdrawal_id binding.
 */
interface RefundCandidateStoreInterface
{
    public function recordReadCandidate(string $operationId, ?string $candidateWithdrawalId, int $retryCount): void;
}
