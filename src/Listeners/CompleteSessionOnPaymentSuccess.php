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
 * it — session → Completed, order adopted as its order_reference.
 *
 * complete() is idempotent (terminal short-circuit + guarded transition), so
 * webhook replays, the reconciliation sweep, and this listener can all race
 * safely — exactly one completion wins.
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

        if ($session->status instanceof Open && ! $this->pinForGatewayPayment($session)) {
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
                'meta' => json_encode(array_merge((array) $session->meta, ['completed_without_pay_step' => true])),
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
