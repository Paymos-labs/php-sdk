<?php

declare(strict_types=1);

namespace Paymos\Plugin;

use Paymos\Client;
use Paymos\Exception\ApiException;

final class RefundCoordinator
{
    /** @var Client */
    private $client;
    /** @var RefundIntentStoreInterface */
    private $store;
    /** @var NativeRefundProjectorInterface */
    private $projector;
    /** @var callable */
    private $clock;

    public function __construct(Client $client, RefundIntentStoreInterface $store,
        NativeRefundProjectorInterface $projector, ?callable $clock = null)
    {
        $this->client = $client;
        $this->store = $store;
        $this->projector = $projector;
        $this->clock = $clock ?: static function () { return new \DateTimeImmutable('now'); };
    }

    /** @return string CashState */
    public function submit(string $operationId): string
    {
        $intent = $this->store->getIntent($operationId);
        return $this->store->withPaymentLock($intent->paymentKey(), function () use ($operationId) {
            return $this->submitLocked($this->store->getIntent($operationId));
        });
    }

    /** Callback handlers pass the stored operation ID only, never event money or a binding. */
    public function reconcile(string $operationId): string
    {
        $intent = $this->store->getIntent($operationId);
        return $this->store->withPaymentLock($intent->paymentKey(), function () use ($operationId) {
            $intent = $this->store->getIntent($operationId);
            $snapshot = null;
            if ($intent->withdrawalId() === null) {
                if ($intent->cashState() === RefundStates::NOT_SUBMITTED) {
                    return RefundStates::NONE;
                }
                if ($intent->cashState() !== RefundStates::UNKNOWN) {
                    return $this->action($intent->cashState());
                }
                // Replay the committed request to recover its ID; never adopt a callback ID.
                $this->submitLocked($intent);
                $intent = $this->store->getIntent($operationId);
                if ($intent->withdrawalId() === null) {
                    return $this->action($intent->cashState());
                }
                $snapshot = $intent->observation();
            }
            if ($snapshot === null) {
                $id = $intent->withdrawalId();
                $snapshot = $this->readAndBind($intent, $id);
            }
            if ($snapshot === null) {
                return $this->action($this->store->getIntent($operationId)->cashState());
            }
            $intent = $this->store->getIntent($operationId);
            if ($intent->projectionState() === RefundStates::NATIVE_REVIEW
                && !in_array($intent->toArray()['review_reason'], array('cash_rewound', 'native_projection_unknown'), true)) {
                return RefundStates::SHOW_REVIEW;
            }
            if ($snapshot->status() !== 'completed') {
                if ($intent->projectionState() === RefundStates::NATIVE_REVIEW) {
                    return RefundStates::SHOW_REVIEW;
                }
                return $this->action($intent->cashState());
            }

            try {
                return $this->store->transaction(function () use ($operationId, $intent, $snapshot) {
                    $result = $this->projector->inspect($operationId);
                    $recovered = $result->kind() === NativeProjectionResult::EXACT_MATCH;
                    if ($result->kind() === NativeProjectionResult::ABSENT) {
                        if ($intent->toArray()['projection'] !== null) {
                            $this->review($intent, 'native_marker_missing');
                            return RefundStates::SHOW_REVIEW;
                        }
                        $result = $this->projector->applyCompleted($intent, $snapshot);
                    }
                    if (!$this->matchesProjection($intent, $snapshot, $result)) {
                        $this->review($intent, $result->reason() ?: 'native_projection_conflict');
                        return RefundStates::SHOW_REVIEW;
                    }
                    $this->store->recordProjection($operationId, $result);
                    $this->store->recordState($operationId, RefundStates::COMPLETED, RefundStates::PROJECTION_APPLIED, null);
                    return $recovered ? RefundStates::RESUME_RECOVERY : RefundStates::APPLY_COMPLETED;
                });
            } catch (\Throwable $error) {
                $this->review($this->store->getIntent($operationId), 'native_projection_unknown');
                return RefundStates::SHOW_REVIEW;
            }
        });
    }

    private function submitLocked(RefundIntent $intent): string
    {
        if ($intent->withdrawalId() !== null
            || !in_array($intent->cashState(), array(RefundStates::NOT_SUBMITTED, RefundStates::UNKNOWN), true)) {
            return $intent->cashState();
        }
        $initial = $intent->cashState() === RefundStates::NOT_SUBMITTED;
        if ($initial) {
            $reason = $this->projector->preflight($intent);
            if ($reason !== null) {
                $this->review($intent, $reason);
                return RefundStates::REVIEW;
            }
        }
        // Persist uncertainty before HTTP, including process death before its response.
        $this->store->transaction(function () use ($intent) {
            $this->store->recordState($intent->operationId(), RefundStates::UNKNOWN, RefundStates::PROJECTION_PENDING, null);
        });
        try {
            $wire = $this->client->refunds()->create($intent->invoiceId(), $intent->mode(), $intent->externalOrderId());
        } catch (ApiException $error) {
            if ($initial && in_array($error->statusCode(), array(400, 401, 403, 404, 409, 410, 422), true)) {
                if ($error->errorCode() === 'idempotency_conflict') {
                    $this->review($intent, 'refund_request_conflict');
                } else {
                    $this->store->recordState($intent->operationId(), RefundStates::FAILED,
                        RefundStates::PROJECTION_NONE, $error->errorCode());
                }
            }
            return $this->store->getIntent($intent->operationId())->cashState();
        } catch (\RuntimeException $error) {
            return RefundStates::UNKNOWN;
        }
        if (!isset($wire['withdrawal_id']) || !is_string($wire['withdrawal_id']) || $wire['withdrawal_id'] === '') {
            return RefundStates::UNKNOWN;
        }
        $this->readAndBind($intent, $wire['withdrawal_id']);
        return $this->store->getIntent($intent->operationId())->cashState();
    }

    private function readAndBind(RefundIntent $intent, string $id): ?VerifiedRefund
    {
        try {
            $wire = $this->client->refunds()->get($id);
            $invoice = $this->client->invoices()->get($intent->invoiceId());
        } catch (ApiException $error) {
            if (in_array($error->statusCode(), array(401, 403, 404), true)) {
                $this->review($intent, 'refund_read_unavailable');
            }
            return null;
        } catch (\RuntimeException $error) {
            return null;
        }
        try {
            $snapshot = VerifiedRefund::fromApi($wire, $invoice, call_user_func($this->clock));
        } catch (\UnexpectedValueException $error) {
            $this->review($intent, 'refund_evidence_invalid');
            return null;
        }
        $pin = $intent->observation();
        if ($snapshot->withdrawalId() !== $id || $snapshot->invoiceId() !== $intent->invoiceId()
            || $snapshot->projectId() !== $intent->projectId() || $snapshot->environment() !== $intent->environment()
            || $snapshot->mode() !== $intent->mode() || $snapshot->externalOrderId() !== $intent->externalOrderId()
            || ($intent->withdrawalId() !== null && $intent->withdrawalId() !== $id)
            || ($pin !== null && (!AmountGuard::amountsEqual($pin->principalDecimal(), $snapshot->principalDecimal())
                || $pin->currency() !== $snapshot->currency() || $pin->network() !== $snapshot->network()
                || !RefundRecipientIdentity::same($pin, $snapshot)))) {
            $this->review($intent, 'refund_binding_conflict');
            return null;
        }
        return $this->store->transaction(function () use ($intent, $id, $snapshot) {
            $binding = $this->store->bindWithdrawal($intent->operationId(), $id);
            if (!in_array($binding, array('bound', 'same'), true)) {
                $this->review($intent, 'refund_binding_conflict');
                return null;
            }
            $this->store->recordObservation($intent->operationId(), $snapshot);
            $state = RefundStates::cashState($snapshot->status());
            $rewound = $intent->projectionState() === RefundStates::PROJECTION_APPLIED && $state !== RefundStates::COMPLETED;
            $review = $rewound || $intent->projectionState() === RefundStates::NATIVE_REVIEW;
            $this->store->recordState($intent->operationId(), $state,
                $review ? RefundStates::NATIVE_REVIEW : ($intent->projectionState() === RefundStates::PROJECTION_APPLIED
                    ? RefundStates::PROJECTION_APPLIED : RefundStates::PROJECTION_PENDING),
                $rewound ? 'cash_rewound' : $intent->toArray()['review_reason']);
            return $snapshot;
        });
    }

    private function matchesProjection(RefundIntent $intent, VerifiedRefund $snapshot, NativeProjectionResult $result): bool
    {
        if ($result->kind() !== NativeProjectionResult::EXACT_MATCH || $result->mode() !== $intent->mode()
            || $result->nativeId() === null || $result->nativeId() === '') {
            return false;
        }
        if ($intent->mode() === RefundStates::OVERPAYMENT) {
            return $result->nativeAmountMinor() === null && $result->nativeCurrency() === null
                && AmountGuard::amountsEqual($snapshot->principalDecimal(), $result->cryptoObservationAmount())
                && $result->cryptoAsset() === $snapshot->currency() . '/' . $snapshot->network();
        }
        $draft = $intent->fullDraft();
        return $draft !== null && $result->nativeAmountMinor() === $draft['amount_minor']
            && $result->nativeCurrency() === $draft['currency'];
    }

    private function review(RefundIntent $intent, string $reason): void
    {
        $this->store->recordState($intent->operationId(), RefundStates::REVIEW, RefundStates::NATIVE_REVIEW, $reason);
    }

    private function action(string $state): string
    {
        switch ($state) {
            case RefundStates::NOT_SUBMITTED: return RefundStates::NONE;
            case RefundStates::FAILED: return RefundStates::SHOW_FAILURE;
            case RefundStates::CANCELLED: return RefundStates::SHOW_CANCELLED;
            case RefundStates::REVIEW: return RefundStates::SHOW_REVIEW;
            default: return RefundStates::SHOW_PENDING;
        }
    }
}
