<?php

declare(strict_types=1);

namespace Paymos\Plugin;

interface RefundIntentStoreInterface
{
    /** Lock covers native guards, HTTP and projections; recheck ownership after HTTP. */
    public function withPaymentLock(string $paymentKey, callable $action);

    /**
     * Native financial writes and marker share this connection. Never enclose HTTP.
     * Validate payment lock ownership on entry and commit, including after external calls.
     */
    public function transaction(callable $action);

    /**
     * Commit before return. Unique installation/token, external ID and withdrawal ID.
     * Same token/payload returns existing; changed payload throws refund_request_conflict.
     * Reject another unresolved intent, completed full or native financial review under the payment lock.
     * Keep terminal history; new intent requires an explicit action and fresh native guards.
     */
    public function createIntent(RefundIntent $intent): RefundIntent;
    public function findByRequestToken(string $installationId, string $token): ?RefundIntent;
    public function getIntent(string $operationId): RefundIntent;
    public function findByExternalId(string $key): ?RefundIntent;
    public function findByWithdrawalId(string $id): ?RefundIntent;

    /** @return string bound|same|conflict Write-once CAS, including provider ID uniqueness. */
    public function bindWithdrawal(string $operationId, string $id): string;

    /** Reject any mismatch with the immutable intent, binding or pinned money/recipient. */
    public function recordObservation(string $operationId, VerifiedRefund $snapshot): void;
    public function recordProjection(string $operationId, NativeProjectionResult $result): void;

    /** Update only this intent; never overwrite another operation's payment projection. */
    public function recordState(string $operationId, string $cashState, string $projectionState, ?string $reason): void;

    /** Commit started before external send; already_started forbids automatic resend. */
    public function claimEffect(string $operationId, string $kind, int $generation): EffectClaim;
    public function recordEffectOutcome(string $operationId, string $kind, int $generation, string $state): void;

    /** Backoff 1/2/5/15/60 min then hourly; >24h review, fair historical sweep every 24h, batch 100. */
    public function listDue(\DateTimeImmutable $now, int $limit, ?string $cursor): RefundIntentPage;
}
