<?php

namespace Lunar\Checkout\Listeners;

use Illuminate\Support\Facades\Log;
use Lunar\Checkout\Contracts\CheckoutDriver;
use Lunar\Checkout\Models\CheckoutSession;
use Lunar\Checkout\States\CheckoutSession\Open;
use Lunar\Checkout\States\CheckoutSession\PaymentProcessing;
use Lunar\Core\Events\PaymentAttemptEvent;
use Lunar\Core\Models\Order;

/**
 * The gateway-agnostic success bridge (spec 0002 §D): any gateway driver that
 * authorises a payment dispatches PaymentAttemptEvent; when the paid order's
 * cart has a session pinned at the pay boundary, the checkout driver completes
 * it — session → Completed, order adopted as its order_reference. A session
 * still Open (the customer confirmed with the gateway without the pay step)
 * is pinned here first; see pinForGatewayPayment().
 *
 * complete() is idempotent (terminal short-circuit + guarded transition), so
 * webhook replays, the reconciliation sweep, and this listener can all race
 * safely — exactly one completion wins.
 *
 * An OrderPlacing listener must not authorise a payment itself: during a
 * synchronous complete() the session is still Open, so this listener would
 * pin and complete it re-entrantly.
 */
class CompleteSessionOnPaymentSuccess
{
    public function __construct(
        private readonly CheckoutDriver $driver,
    ) {}

    public function handle(PaymentAttemptEvent $event): void
    {
        $authorize = $event->paymentAuthorize;

        if (! $authorize->success || $authorize->orderId === null) {
            return;
        }

        $cartId = Order::query()->whereKey($authorize->orderId)->value('cart_id');

        if ($cartId === null) {
            return;
        }

        $session = CheckoutSession::query()
            ->where('cart_reference', (string) $cartId)
            ->whereIn('status', [PaymentProcessing::$name, Open::$name])
            ->orderByRaw('case when status = ? then 0 else 1 end', [PaymentProcessing::$name])
            ->first();

        if ($session === null) {
            return;
        }

        /*
         * A hold-mode session stays Open on purpose until the customer
         * finishes on the confirm page, which captures at reconcile; it is
         * never adopted from here.
         */
        if ($session->status instanceof Open && ($session->isHoldMode() || ! $this->pinForGatewayPayment($session))) {
            return;
        }

        $this->driver->complete($session);
    }

    /**
     * The customer confirmed the payment with the gateway directly (e.g.
     * Stripe.js) without going through the pay boundary, so the session is
     * still Open. The money is already taken, so refusing the order would
     * strand it; instead pin the session the way the pay boundary does,
     * against the live cart the gateway just charged for, and let complete()
     * run its listeners (OrderPlacing, contact email, payment method) like
     * any other completion. Expiry is not checked: the payment happened.
     *
     * Returns false when another process moved the session first.
     */
    private function pinForGatewayPayment(CheckoutSession $session): bool
    {
        $live = $this->driver->snapshot($session);

        $pinned = CheckoutSession::query()
            ->whereKey($session->getKey())
            ->where('status', Open::$name)
            ->update([
                'status' => PaymentProcessing::$name,
                'amount_subtotal' => $live->amountSubtotal,
                'amount_total' => $live->amountTotal,
                'cart_fingerprint' => $live->fingerprint,
                'payment_processing_at' => now(),
                // A JSON-path write, so a concurrent meta write (intent
                // renewal, payment handle) is not overwritten.
                'meta->completed_without_pay_step' => true,
                'updated_at' => now(),
            ]);

        $session->refresh();

        if ($pinned !== 1) {
            return $session->status instanceof PaymentProcessing;
        }

        Log::warning('Checkout session completed from a gateway payment without the pay step.', [
            'checkout_session' => $session->uuid,
            'cart' => $session->cart_reference,
        ]);

        return true;
    }
}
