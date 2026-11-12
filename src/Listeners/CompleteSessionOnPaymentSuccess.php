<?php

namespace Lunar\Checkout\Listeners;

use Illuminate\Support\Facades\DB;
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
            // At most one: active_cart_reference is unique across live
            // (Open / PaymentProcessing) sessions.
            ->whereIn('status', [PaymentProcessing::$name, Open::$name])
            ->first();

        if ($session === null) {
            return;
        }

        /*
         * A hold-mode session stays Open on purpose until the customer
         * finishes on the confirm page, which captures at reconcile; it is
         * never adopted from here.
         */
        if ($session->status instanceof Open && $session->isHoldMode()) {
            return;
        }

        $this->rememberPlacedOrder($session, $authorize->orderId);

        if ($session->status instanceof Open && ! $this->pinForGatewayPayment($session)) {
            return;
        }

        $this->driver->complete($session);
    }

    /**
     * Notes on the session which order the gateway placed for the intent the
     * session carries, before anything else can fail. If complete() then
     * throws, reconciliation must not refund that order's money, and it
     * cannot tell by time: a session this listener pins is pinned after the
     * order was placed. The intent is kept beside the order so the note
     * stops counting once the session moves on to another intent.
     *
     * Written under the row lock for the reason pinForGatewayPayment() gives,
     * in a transaction of its own that commits before that method takes its
     * snapshot.
     */
    private function rememberPlacedOrder(CheckoutSession $session, int $orderId): void
    {
        DB::transaction(function () use ($session, $orderId): void {
            $locked = CheckoutSession::query()
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || ! ($locked->status instanceof Open || $locked->status instanceof PaymentProcessing)) {
                return;
            }

            CheckoutSession::query()
                ->whereKey($session->getKey())
                ->update([
                    'meta' => json_encode(array_merge((array) $locked->meta, [
                        'gateway_placed_order' => ['order' => $orderId, 'intent' => $locked->payment_intent_ref],
                    ])),
                ]);
        });

        $session->refresh();
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
        /*
         * Re-read under a row lock and write the merged meta, so a
         * concurrent meta write (intent renewal, payment handle) is not
         * lost. Not a JSON-path update: MySQL's JSON_SET on a NULL column
         * returns NULL and would drop the flag.
         *
         * The status moves with the same guarded raw update the pay
         * boundary uses (LunarCheckoutDriver::assertReadyForPayment()):
         * session states change through these one-statement guards, not
         * model transitions.
         *
         * The snapshot is taken before the session row is locked. Taking it
         * writes the cart row's totals cache, and the pay boundary locks the
         * cart row before it writes the session row, so snapshotting under
         * the session lock could deadlock against it. The session lock never
         * guarded the cart: a cart edit after the snapshot leaves a
         * fingerprint that complete() rejects under the cart-row lock, and a
         * session another process moved meanwhile fails the Open check below.
         */
        $live = $this->driver->snapshot($session);

        $pinned = DB::transaction(function () use ($session, $live): int {
            $locked = CheckoutSession::query()
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || ! $locked->status instanceof Open) {
                return 0;
            }

            return CheckoutSession::query()
                ->whereKey($session->getKey())
                ->where('status', Open::$name)
                ->update([
                    'status' => PaymentProcessing::$name,
                    'amount_subtotal' => $live->amountSubtotal,
                    'amount_total' => $live->amountTotal,
                    'cart_fingerprint' => $live->fingerprint,
                    'payment_processing_at' => now(),
                    'meta' => json_encode(array_merge((array) $locked->meta, ['completed_without_pay_step' => true])),
                    'updated_at' => now(),
                ]);
        });

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
