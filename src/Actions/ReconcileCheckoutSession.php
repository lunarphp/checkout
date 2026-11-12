<?php

namespace Lunar\Checkout\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\DetectsLostConnections;
use Lunar\Checkout\Contracts\Actions\ReconcilesCheckoutSession;
use Lunar\Checkout\Contracts\CheckoutDriver;
use Lunar\Checkout\Events\CheckoutCompletionFailed;
use Lunar\Checkout\Events\CheckoutSessionInvalidated;
use Lunar\Checkout\Events\CheckoutSessionReconciliationStalled;
use Lunar\Checkout\Exceptions\PaymentConfirmationException;
use Lunar\Checkout\Models\CheckoutSession;
use Lunar\Checkout\States\CheckoutSession\Cancelled;
use Lunar\Checkout\States\CheckoutSession\Completed;
use Lunar\Checkout\States\CheckoutSession\Open;
use Lunar\Checkout\States\CheckoutSession\PaymentProcessing;
use Lunar\Checkout\Support\PaymentIntentGateway;
use Lunar\Core\Contracts\ReconcilesPaymentIntents;
use Lunar\Core\Contracts\SupportsPaymentHolds;
use Lunar\Core\DataObjects\PriceValue;
use Lunar\Core\Enums\PaymentIntentStatus;
use Lunar\Core\Exceptions\PaymentIntentException;
use Lunar\Core\Models\Cart;
use Lunar\Core\Models\Order;

/**
 * Bounded resolution of a `PaymentProcessing` session (spec 0010 §F): the
 * gateway's actual intent outcome decides — captured completes (or refunds on
 * mismatch: no charge survives without an order), voided/failed reopens,
 * pending reschedules without consuming an attempt. Exhausted attempts fire
 * {@see CheckoutSessionReconciliationStalled} once; the operator `--resolve`
 * override is the sanctioned stall exit.
 */
final class ReconcileCheckoutSession implements ReconcilesCheckoutSession
{
    use DetectsConcurrencyErrors;
    use DetectsLostConnections;

    public function __construct(
        private CheckoutDriver $driver,
        private PaymentIntentGateway $gateways,
        private Dispatcher $events,
    ) {}

    public function execute(CheckoutSession $session, ?string $resolve = null): string
    {
        if (! $session->status instanceof PaymentProcessing) {
            return 'not-applicable';
        }

        $gateway = $this->gateways->for($session);
        $reference = $session->payment_intent_ref;
        $cart = Cart::query()->find((int) $session->cart_reference);

        if ($resolve !== null) {
            return $this->resolveByOperator($session, $gateway, $cart, $reference, $resolve);
        }

        if ($gateway === null || $cart === null || $reference === null) {
            return $this->recordAttempt($session);
        }

        try {
            $status = $gateway->fetchIntent($cart, $reference)?->status;
        } catch (PaymentIntentException) {
            return $this->recordAttempt($session);
        }

        return match ($status) {
            // Not an intent the gateway created for this cart: unknowable.
            null => $this->recordAttempt($session),

            // Legitimately still in flight (the customer has yet to act, or
            // a slow method has yet to settle): reschedule without consuming
            // an attempt (spec 0010 §F).
            PaymentIntentStatus::RequiresAction,
            PaymentIntentStatus::Processing => 'pending',

            PaymentIntentStatus::Captured,
            PaymentIntentStatus::RequiresCapture => $this->completeOrRefund($session, $gateway, $cart, $reference, $status),

            PaymentIntentStatus::Voided,
            PaymentIntentStatus::Failed => $this->reopen($session, 'intent_'.$status->value),
        };
    }

    public function release(CheckoutSession $session): string
    {
        if (! $session->status instanceof PaymentProcessing) {
            return 'not-applicable';
        }

        $gateway = $this->gateways->for($session);
        $reference = $session->payment_intent_ref;

        if ($reference !== null) {
            // An intent exists, so money may exist. Reopen only on the
            // gateway's word that it was not captured; anything unknowable
            // stays frozen and the reconciliation sweep owns it.
            $cart = Cart::query()->find((int) $session->cart_reference);

            if ($gateway === null || $cart === null) {
                return 'unconfirmed';
            }

            try {
                $status = $gateway->fetchIntent($cart, $reference)?->status;
            } catch (PaymentIntentException) {
                return 'unconfirmed';
            }

            if ($status === null) {
                return 'unconfirmed';
            }

            if (in_array($status, [PaymentIntentStatus::Captured, PaymentIntentStatus::RequiresCapture], true)) {
                // An authorise-only hold at RequiresCapture is not money
                // moved: the customer may keep editing and re-confirm, so
                // reopen with the hold intact instead of forcing completion.
                if (! ($session->isHoldMode() && $status === PaymentIntentStatus::RequiresCapture)) {
                    return $this->completeOrRefund($session, $gateway, $cart, $reference, $status);
                }
            }
        }

        // Unlike reopen(), the intent reference survives: the customer's
        // mounted payment element is still bound to it, the intent is still
        // confirmable, and the retry re-syncs its amount at the pay boundary.
        $grace = (int) config('lunar.checkout.session.reopen_grace_minutes', 30);

        $session->transitionGuarded([PaymentProcessing::$name], Open::$name, [
            'payment_processing_at' => null,
            'reconciliation_attempts' => 0,
            'expires_at' => now()->addMinutes($grace),
        ]);

        return 'released';
    }

    /**
     * Captured money resolves to exactly one of: an order, or money back.
     * Money back is flavour-aware: an authorisation that was never captured
     * (a hold, or a policy-mode requires_capture intent) voids; anything
     * actually captured (a standard intent, or a hold captured by this very
     * call) refunds.
     */
    private function completeOrRefund(
        CheckoutSession $session,
        ReconcilesPaymentIntents $gateway,
        Cart $cart,
        string $reference,
        PaymentIntentStatus $status,
    ): string {
        // An authorise-only hold is not money moved yet: capture it now, in
        // the one idempotent place every completion path funnels through, so
        // a crash between pin and capture recovers on the next reconcile.
        // Policy-mode requires_capture (store-wide manual capture, settled
        // later from the panel) keeps its existing complete-without-capture
        // behaviour.
        $holdCaptured = false;

        if ($session->isHoldMode() && $gateway instanceof SupportsPaymentHolds) {
            try {
                $gateway->captureHold($cart, $reference, $this->pinnedAmount($session, $cart));
                $holdCaptured = true;
            } catch (PaymentIntentException $e) {
                report($e);

                // Nothing was captured: the intent reference survives so the
                // customer can retry against the SAME hold, or the wallet
                // re-opens against it, instead of minting a fresh
                // authorisation.
                $this->reopenKeepingIntent($session, 'hold_capture_failed');
                $this->events->dispatch(new CheckoutCompletionFailed($session, 'hold-capture-failed'));

                return 'reopened';
            }
        }

        try {
            $this->driver->complete($session);

            return 'completed';
        } catch (PaymentConfirmationException) {
            // The cart no longer matches what the customer paid for.
        } catch (\Throwable $e) {
            // Any other failure rolled the completion back and left money
            // with no order (spec 0010 §E.2). A deadlock or a dropped
            // connection is retried on a later pass instead; captureHold()
            // is idempotent per reference, so a captured hold survives that.
            report($e);

            if ($this->causedByConcurrencyError($e) || $this->causedByLostConnection($e)) {
                return $this->recordAttempt($session);
            }
        }

        return $this->moneyBack($session, $gateway, $cart, $reference, $holdCaptured || $status === PaymentIntentStatus::Captured);
    }

    /**
     * Under the session row lock, held until the session is reopened: a
     * concurrent completion (the webhook) either committed first, and is
     * seen here, or waits on its guarded write and then finds the session
     * no longer PaymentProcessing. Events fire only after the reopen commits,
     * so a throwing listener cannot roll back a reopen whose money has
     * already gone back.
     */
    private function moneyBack(
        CheckoutSession $session,
        ReconcilesPaymentIntents $gateway,
        Cart $cart,
        string $reference,
        bool $captured,
    ): string {
        [$outcome, $event] = $session->getConnection()->transaction(function () use ($session, $gateway, $cart, $reference, $captured): array {
            $locked = CheckoutSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status instanceof Completed) {
                return ['completed', null];
            }

            // Another process reopened it, or reopened and re-pinned it
            // against a new intent: this pass's money decision is stale.
            if (! $locked->status instanceof PaymentProcessing || $locked->payment_intent_ref !== $reference) {
                return ['not-applicable', null];
            }

            // The gateway's own authorize() placed an order for this cart
            // since the pin, before complete() failed: returning the money
            // would leave a placed order unpaid. The operator resolves it.
            // An order an earlier checkout of the same cart placed does not
            // count.
            $placedForThisPayment = Order::query()
                ->where('cart_id', (int) $session->cart_reference)
                ->whereNotNull('placed_at')
                ->when($locked->payment_processing_at, fn ($query, $pinnedAt) => $query->where('placed_at', '>=', $pinnedAt))
                ->exists();

            if ($placedForThisPayment) {
                return ['order-placed', null];
            }

            // Nothing was captured, so there is nothing to refund: release
            // the authorisation. A hold-mode session with no capture here
            // means $gateway lacked SupportsPaymentHolds (a
            // misconfiguration); voiding is still the safe answer.
            if (! $captured) {
                try {
                    $gateway->voidIntent($cart, $reference);
                } catch (PaymentIntentException) {
                    return ['void-failed', new CheckoutCompletionFailed($session, 'void-failed')];
                }

                $holdMode = $session->isHoldMode();
                $this->reopen($session, 'voided');

                return ['voided', new CheckoutCompletionFailed($session, $holdMode ? 'hold-voided' : 'voided')];
            }

            try {
                $refund = $gateway->refundIntent(
                    $cart,
                    $reference,
                    $this->pinnedAmount($session, $cart),
                    'lunar-checkout-refund:'.$reference,
                );
            } catch (PaymentIntentException) {
                return ['refund-failed', new CheckoutCompletionFailed($session, 'refund-failed')];
            }

            $this->reopen($session, 'refunded');

            return ['refunded', new CheckoutCompletionFailed($session, 'refunded', $refund->reference)];
        });

        // Count the attempt before any listener runs, so a throwing
        // listener cannot stop the session reaching its stall.
        $result = match ($outcome) {
            'void-failed', 'refund-failed', 'order-placed' => $this->recordAttempt($session),
            default => $outcome,
        };

        if ($event !== null) {
            $this->events->dispatch($event);
        }

        return $result;
    }

    private function resolveByOperator(
        CheckoutSession $session,
        ?ReconcilesPaymentIntents $gateway,
        ?Cart $cart,
        ?string $reference,
        string $resolve,
    ): string {
        return match ($resolve) {
            'complete' => $this->completeForOperator($session),
            'refund' => $gateway !== null && $cart !== null && $reference !== null
                ? $this->completeOrRefundByForce($session, $gateway, $cart, $reference)
                : 'refund-failed',
            'cancel' => $this->cancelForOperator($session),
            default => throw new \InvalidArgumentException("Unknown resolution [{$resolve}]."),
        };
    }

    private function completeForOperator(CheckoutSession $session): string
    {
        $this->driver->complete($session);

        return 'completed';
    }

    /**
     * Operator asserts the charge must not stand: refund without trying to
     * complete first.
     */
    private function completeOrRefundByForce(
        CheckoutSession $session,
        ReconcilesPaymentIntents $gateway,
        Cart $cart,
        string $reference,
    ): string {
        $refund = $gateway->refundIntent(
            $cart,
            $reference,
            $this->pinnedAmount($session, $cart),
            'lunar-checkout-refund:'.$reference,
        );

        $this->reopen($session, 'refunded');
        $this->events->dispatch(new CheckoutCompletionFailed($session, 'refunded', $refund->reference));

        return 'refunded';
    }

    /**
     * Operator asserts the gateway outcome is void/no-charge: terminalize.
     */
    private function cancelForOperator(CheckoutSession $session): string
    {
        $session->transitionGuarded([PaymentProcessing::$name], Cancelled::$name, [
            'active_cart_reference' => null,
            'cancelled_at' => now(),
        ]);

        $this->events->dispatch(new CheckoutSessionInvalidated($session, 'operator_cancelled'));

        return 'cancelled';
    }

    /**
     * Back to `Open` with return-path hygiene (spec 0010 §E.2/§F): intent refs
     * cleared in the same guarded statement, expiry re-armed with a grace
     * window so the session doesn't reopen pre-expired.
     */
    private function reopen(CheckoutSession $session, string $reason): string
    {
        $grace = (int) config('lunar.checkout.session.reopen_grace_minutes', 30);

        $session->transitionGuarded([PaymentProcessing::$name], Open::$name, [
            'payment_intent_ref' => null,
            'payment_processing_at' => null,
            'reconciliation_attempts' => 0,
            'expires_at' => now()->addMinutes($grace),
        ]);

        return $reason;
    }

    /**
     * Back to `Open` like {@see reopen()} but the intent reference survives:
     * nothing was captured (the hold's own capture call failed), so there is
     * no double-hold risk in leaving the customer bound to the same
     * authorisation instead of forcing a fresh one.
     */
    private function reopenKeepingIntent(CheckoutSession $session, string $reason): string
    {
        $grace = (int) config('lunar.checkout.session.reopen_grace_minutes', 30);

        $session->transitionGuarded([PaymentProcessing::$name], Open::$name, [
            'payment_processing_at' => null,
            'reconciliation_attempts' => 0,
            'expires_at' => now()->addMinutes($grace),
        ]);

        return $reason;
    }

    private function pinnedAmount(CheckoutSession $session, Cart $cart): PriceValue
    {
        return new PriceValue((int) $session->amount_total, $cart->currency);
    }

    private function recordAttempt(CheckoutSession $session): string
    {
        $maxAttempts = (int) config('lunar.checkout.reconciliation.max_attempts', 5);

        // Only a session still awaiting resolution counts an attempt: one a
        // concurrent completion or reopen moved on must not raise a stall.
        $counted = CheckoutSession::query()
            ->whereKey($session->getKey())
            ->where('status', PaymentProcessing::$name)
            ->increment('reconciliation_attempts');

        $session->refresh();

        if ($counted === 0) {
            return 'not-applicable';
        }

        if ($session->reconciliation_attempts < $maxAttempts) {
            return 'retry';
        }

        // Fire the stall event ONCE; the meta flag drops the session to the
        // low-frequency tier and feeds the post-stall 409 reason (0010 §F).
        if (! ($session->meta['reconciliation_stalled'] ?? false)) {
            $session->meta = array_merge(
                (array) ($session->meta?->getArrayCopy() ?? []),
                ['reconciliation_stalled' => true],
            );
            $session->save();

            $this->events->dispatch(new CheckoutSessionReconciliationStalled($session));
        }

        return 'stalled';
    }
}
