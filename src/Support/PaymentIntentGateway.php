<?php

namespace Lunar\Checkout\Support;

use Lunar\Checkout\Models\CheckoutSession;
use Lunar\Core\Contracts\ReconcilesPaymentIntents;
use Lunar\Core\Managers\PaymentManager;
use Lunar\Core\Models\Cart;

/**
 * Resolves the gateway driver behind a session's in-flight intent, when that
 * driver opted into the {@see ReconcilesPaymentIntents} capability. The payment
 * method handle is recorded on `meta.payment_method` when the intent is
 * created. Null means the intent's outcome cannot be confirmed — callers treat
 * that as "unabortable" and leave the session for reconciliation (0010 §F).
 */
class PaymentIntentGateway
{
    public function __construct(
        private PaymentManager $payments,
    ) {}

    public function for(CheckoutSession $session): ?ReconcilesPaymentIntents
    {
        $handle = $session->meta['payment_method'] ?? null;

        if (! is_string($handle) || $handle === '') {
            return null;
        }

        try {
            $driver = $this->payments->driver($handle);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $driver instanceof ReconcilesPaymentIntents ? $driver : null;
    }

    /**
     * The cart the intent verbs are scoped to, pruned or not. Lunar prunes
     * idle carts by soft-deleting them, and money held or taken against one
     * must still be voidable and refundable afterwards.
     */
    public function cartFor(CheckoutSession $session): ?Cart
    {
        return Cart::query()->withTrashed()->find((int) $session->cart_reference);
    }
}
