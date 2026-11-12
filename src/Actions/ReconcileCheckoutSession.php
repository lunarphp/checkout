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
use Lunar\Checkout\Listeners\CompleteSessionOnPaymentSuccess;
use Lunar\Checkout\Models\CheckoutSession;
use Lunar\Checkout\States\CheckoutSession\Cancelled;
use Lunar\Checkout\States\CheckoutSession\Completed;
use Lunar\Checkout\States\CheckoutSession\Open;
use Lunar\Checkout\States\CheckoutSession\PaymentProcessing;
use Lunar\Checkout\Support\PaymentIntentGateway;
use Lunar\Core\Contracts\ReconcilesPaymentIntents;
use Lunar\Core\Contracts\SupportsPaymentHolds;
use Lunar\Core\DataObjects\PaymentIntentSnapshot;
use Lunar\Core\DataObjects\PriceValue;
use Lunar\Core\Enums\PaymentIntentStatus;
use Lunar\Core\Exceptions\PaymentIntentException;
use Lunar\Core\Models\Cart;
use Lunar\Core\Models\Currency;
use Lunar\Core\Models\Order;

/**
 * Bounded resolution of a `PaymentProcessing` session (spec 0010 §F): the
 * gateway's actual intent outcome decides — captured completes (or refunds on
 * mismatch: no charge survives without an order), voided/failed reopens,
 * pending reschedules without consuming an attempt, and an intent left
 * waiting on the customer past its window is voided and the session reopens.
 * Exhausted attempts fire
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
        $cart = $this->gateways->cartFor($session);

        if ($resolve !== null) {
            return $this->resolveByOperator($session, $gateway, $cart, $reference, $resolve);
        }

        if ($gateway === null || $cart === null || $reference === null) {
            return $this->recordAttempt($session);
        }

        try {
            $intent = $gateway->fetchIntent($cart, $reference);
        } catch (PaymentIntentException) {
            return $this->recordAttempt($session);
        }

        $status = $intent?->status;

        return match ($status) {
            // Not an intent the gateway created for this cart: unknowable.
            null => $this->recordAttempt($session),

            // A slow method that has yet to settle does so on its own:
            // reschedule without consuming an attempt (spec 0010 §F).
            PaymentIntentStatus::Processing => 'pending',

            // Waiting on the customer covers a recorded decline and an
            // abandoned challenge as well as one still under way, and nothing
            // settles the first two. Past the window the cart is freed for a
            // retry.
            PaymentIntentStatus::RequiresAction => $this->waitedOnCustomerTooLong($session)
                ? $this->voidAndReopen($session, $gateway, $cart, $reference, 'intent_'.$status->value)
                : 'pending',

            PaymentIntentStatus::Captured,
            PaymentIntentStatus::RequiresCapture => $this->completeOrRefund($session, $gateway, $cart, $reference, $intent),

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
            $cart = $this->gateways->cartFor($session);

            if ($gateway === null || $cart === null) {
                return 'unconfirmed';
            }

            try {
                $intent = $gateway->fetchIntent($cart, $reference);
            } catch (PaymentIntentException) {
                return 'unconfirmed';
            }

            if ($intent === null) {
                return 'unconfirmed';
            }

            if (in_array($intent->status, [PaymentIntentStatus::Captured, PaymentIntentStatus::RequiresCapture], true)) {
                // An authorise-only hold at RequiresCapture is not money
                // moved: the customer may keep editing and re-confirm, so
                // reopen with the hold intact instead of forcing completion.
                if (! ($session->isHoldMode() && $intent->status === PaymentIntentStatus::RequiresCapture)) {
                    return $this->completeOrRefund($session, $gateway, $cart, $reference, $intent);
                }
            }
        }

        return $this->reopenKeepingIntent($session, 'released');
    }

    /**
     * Gives up on an intent the customer never finished. The void comes
     * first: an intent left confirmable could take a payment after the
     * session reopened, when nothing settles it and a retry mints a second
     * intent. A void the gateway refuses means the payment may have gone
     * through since the fetch, so the session stays pinned and the attempt
     * counts: the next pass reads the real status.
     */
    private function voidAndReopen(
        CheckoutSession $session,
        ReconcilesPaymentIntents $gateway,
        Cart $cart,
        string $reference,
        string $reason,
    ): string {
        try {
            $gateway->voidIntent($cart, $reference);
        } catch (PaymentIntentException) {
            return $this->recordAttempt($session);
        }

        return $this->reopen($session, $reason);
    }

    /**
     * Captured money resolves to exactly one of: an order, or money back.
     * Money back is flavour-aware: an authorisation that was never captured
     * (a hold, or a policy-mode requires_capture intent) voids; anything
     * actually captured (a standard intent, or a hold captured by this very
     * call) refunds.
     *
     * A gateway keeps reporting a refunded intent as captured, so money an
     * earlier pass returned is recognised by the refunded amount. A full
     * refund whose reopen never committed (the pass died, or the gateway's
     * answer was lost) finishes here without a second refund call. A partial
     * one is not this class's doing and leaves money with no order, so it
     * stalls to the operator. Neither ever completes.
     */
    private function completeOrRefund(
        CheckoutSession $session,
        ReconcilesPaymentIntents $gateway,
        Cart $cart,
        string $reference,
        PaymentIntentSnapshot $intent,
    ): string {
        if ($intent->refunded->value > 0) {
            return $intent->refunded->value >= $intent->captured->value
                ? $this->moneyBack($session, $gateway, $cart, $reference, captured: true, alreadyRefunded: true)
                : $this->recordAttempt($session);
        }

        // An authorise-only hold is not money moved yet: capture it now, in
        // the one idempotent place every completion path funnels through, so
        // a crash between pin and capture recovers on the next reconcile.
        // Policy-mode requires_capture (store-wide manual capture, settled
        // later from the panel) keeps its existing complete-without-capture
        // behaviour.
        $holdCaptured = false;

        if ($session->isHoldMode() && $gateway instanceof SupportsPaymentHolds) {
            try {
                $gateway->captureHold($cart, $reference, $this->pinnedAmount($session));
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

        return $this->moneyBack($session, $gateway, $cart, $reference, $holdCaptured || $intent->status === PaymentIntentStatus::Captured);
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
        bool $alreadyRefunded = false,
    ): string {
        [$outcome, $event] = $session->getConnection()->transaction(function () use ($session, $gateway, $cart, $reference, $captured, $alreadyRefunded): array {
            $locked = CheckoutSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status instanceof Completed) {
                return ['completed', null];
            }

            // Another process reopened it, or reopened and re-pinned it
            // against a new intent: this pass's money decision is stale.
            if (! $locked->status instanceof PaymentProcessing || $locked->payment_intent_ref !== $reference) {
                return ['not-applicable', null];
            }

            // The gateway's own authorize() placed an order for this
            // payment before complete() failed: returning the money would
            // leave a placed order unpaid. The operator resolves it.
            if ($this->orderPlacedForThisPayment($locked, $reference)) {
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

            // A refund found on the intent has no reference to pass on: the
            // snapshot carries amounts only, and the call that made it never
            // returned one.
            $refundReference = null;

            if (! $alreadyRefunded) {
                try {
                    $refundReference = $gateway->refundIntent(
                        $cart,
                        $reference,
                        $this->pinnedAmount($session),
                        'lunar-checkout-refund:'.$reference,
                    )->reference;
                } catch (PaymentIntentException) {
                    return ['refund-failed', new CheckoutCompletionFailed($session, 'refund-failed')];
                }
            }

            $this->reopen($session, 'refunded');

            return ['refunded', new CheckoutCompletionFailed($session, 'refunded', $refundReference)];
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

    /**
     * Whether the gateway placed an order for the payment the session is
     * pinned on. Two signals, either enough: the order the gateway announced
     * for this intent ({@see CompleteSessionOnPaymentSuccess} notes it on the
     * session), or an order placed since the pin, for a placement whose
     * announcement never reached that listener. An order an earlier checkout
     * of the same cart placed matches neither.
     */
    private function orderPlacedForThisPayment(CheckoutSession $session, string $reference): bool
    {
        $placed = Order::query()
            ->where('cart_id', (int) $session->cart_reference)
            ->whereNotNull('placed_at');

        $announced = $session->meta['gateway_placed_order'] ?? null;

        if (($announced['intent'] ?? null) === $reference && (clone $placed)->whereKey($announced['order'] ?? null)->exists()) {
            return true;
        }

        return $placed
            ->when($session->payment_processing_at, fn ($query, $pinnedAt) => $query->where('placed_at', '>=', $pinnedAt))
            ->exists();
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
            $this->pinnedAmount($session),
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
     * Back to `Open` like {@see reopen()} but the intent reference survives.
     * Nothing was captured, so the customer stays bound to the same intent or
     * authorisation instead of a fresh one: the mounted payment element still
     * points at it, it is still confirmable, and the retry re-syncs its
     * amount at the pay boundary.
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

    /**
     * A pin with no timestamp can never age into the window, so it counts as
     * past it rather than holding the cart for good.
     */
    private function waitedOnCustomerTooLong(CheckoutSession $session): bool
    {
        $minutes = (int) config('lunar.checkout.reconciliation.requires_action_minutes', 30);

        return $session->payment_processing_at?->lte(now()->subMinutes($minutes)) ?? true;
    }

    /**
     * The pinned total in the currency it was pinned in. The cart's currency
     * may have changed since, and a gateway refuses an amount in another
     * currency from the intent's, or missizes it.
     *
     * @throws PaymentIntentException when the store no longer has that
     *                                currency, rather than guess another
     */
    private function pinnedAmount(CheckoutSession $session): PriceValue
    {
        $currency = Currency::query()->where('code', $session->currency_code)->first()
            ?? throw new PaymentIntentException(
                "Checkout session [{$session->uuid}] was pinned in [{$session->currency_code}], which this store no longer has.",
            );

        return new PriceValue((int) $session->amount_total, $currency);
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
