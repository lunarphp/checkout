<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Lunar\Checkout\Contracts\CheckoutDriver;
use Lunar\Checkout\Events\OrderPlacing;
use Lunar\Checkout\States\CheckoutSession\Completed;
use Lunar\Checkout\States\CheckoutSession\Open;
use Lunar\Core\DataObjects\PaymentAuthorize;
use Lunar\Core\Events\PaymentAttemptEvent;
use Lunar\Core\Facades\CartSession;
use Lunar\Core\Models\Order;
use Lunar\Tests\Checkout\TestCase;
use Lunar\Tests\Checkout\Utils\CheckoutCart;

uses(TestCase::class, RefreshDatabase::class);

/**
 * The gateway placed an order for the cart (its webhook authorised a payment
 * the customer confirmed client-side) and announced it the way every gateway
 * driver does.
 */
function gatewayPlacesOrder(Order $order, bool $success = true): void
{
    $order->update(['placed_at' => now()]);

    PaymentAttemptEvent::dispatch(new PaymentAuthorize(
        success: $success,
        orderId: $order->id,
        paymentType: 'stripe',
    ));
}

it('completes a session the customer never pinned when the gateway takes payment for its cart', function () {
    Event::fake([OrderPlacing::class]);

    $cart = CheckoutCart::orderable();
    CartSession::use($cart);
    $session = app(CheckoutDriver::class)->resolveOrCreateSession($cart);

    expect($session->status)->toBeInstanceOf(Open::class);

    $order = $cart->createOrder();
    gatewayPlacesOrder($order);

    $session->refresh();

    expect($session->status)->toBeInstanceOf(Completed::class)
        ->and((string) $session->order_reference)->toBe((string) $order->id)
        ->and($session->meta['completed_without_pay_step'] ?? null)->toBeTrue();

    Event::assertDispatchedTimes(OrderPlacing::class, 1);
    Event::assertDispatched(OrderPlacing::class, fn (OrderPlacing $event): bool => $event->order->id === $order->id);
});

it('leaves an open session alone when the gateway reports a failed payment', function () {
    $cart = CheckoutCart::orderable();
    CartSession::use($cart);
    $session = app(CheckoutDriver::class)->resolveOrCreateSession($cart);

    gatewayPlacesOrder($cart->createOrder(), success: false);

    expect($session->refresh()->status)->toBeInstanceOf(Open::class);
});
