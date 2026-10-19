<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Lunar\Checkout\Contracts\CheckoutDriver;
use Lunar\Checkout\Contracts\PaymentMethodRegistry;
use Lunar\Checkout\Events\OrderPlacing;
use Lunar\Checkout\Models\CheckoutSession;
use Lunar\Checkout\PaymentMethods\AbstractPaymentMethod;
use Lunar\Checkout\States\CheckoutSession\Cancelled;
use Lunar\Checkout\States\CheckoutSession\Completed;
use Lunar\Checkout\States\CheckoutSession\Open;
use Lunar\Checkout\States\CheckoutSession\PaymentProcessing;
use Lunar\Core\Contracts\CreatesPaymentIntents;
use Lunar\Core\DataObjects\PaymentAuthorize;
use Lunar\Core\DataObjects\PaymentIntentDescriptor;
use Lunar\Core\Events\PaymentAttemptEvent;
use Lunar\Core\Facades\CartSession;
use Lunar\Core\Facades\Payments;
use Lunar\Core\Models\Cart;
use Lunar\Core\Models\Order;
use Lunar\Core\PaymentTypes\OfflinePayment;
use Lunar\Tests\Checkout\TestCase;
use Lunar\Tests\Checkout\Utils\CheckoutCart;

uses(TestCase::class, RefreshDatabase::class);

/** A Stripe-like gateway: the browser gets an intent it can confirm directly. */
class WebhookFirstIntentGateway extends OfflinePayment implements CreatesPaymentIntents
{
    public function createIntent(Cart $cart): PaymentIntentDescriptor
    {
        return new PaymentIntentDescriptor('pi_webhook_first_'.$cart->id, 'secret_'.$cart->id);
    }
}

class WebhookFirstIntentMethod extends AbstractPaymentMethod
{
    public function handle(): string
    {
        return 'webhook-first-card';
    }

    public function label(): string
    {
        return 'Card';
    }

    public function driver(): string
    {
        return 'webhook-first-intents';
    }

    public function requiresIntent(): bool
    {
        return true;
    }

    public function component(): string
    {
        return 'stripe-card';
    }
}

/**
 * Swap the checkout driver for a proxy whose snapshot() first runs $race.
 * The listener calls snapshot() between its locked read and its guarded
 * update, so this exercises the guarded update's lost-race fallback (in
 * production the row lock serialises the other writer instead).
 */
function raceTheListener(Closure $race): void
{
    $real = app(CheckoutDriver::class);
    $proxy = Mockery::mock($real)->makePartial();
    $proxy->shouldReceive('snapshot')->andReturnUsing(function (CheckoutSession $session) use ($real, $race) {
        $race($session);

        return $real->snapshot($session);
    });

    app()->instance(CheckoutDriver::class, $proxy);
}

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

it('completes only once when the gateway replays the success', function () {
    Event::fake([OrderPlacing::class]);

    $cart = CheckoutCart::orderable();
    CartSession::use($cart);
    $session = app(CheckoutDriver::class)->resolveOrCreateSession($cart);

    $order = $cart->createOrder();
    gatewayPlacesOrder($order);
    gatewayPlacesOrder($order->fresh());

    expect($session->refresh()->status)->toBeInstanceOf(Completed::class);

    Event::assertDispatchedTimes(OrderPlacing::class, 1);
});

it('adopts the payment even when the open session has expired', function () {
    $cart = CheckoutCart::orderable();
    CartSession::use($cart);
    $session = app(CheckoutDriver::class)->resolveOrCreateSession($cart);
    $session->forceFill(['expires_at' => now()->subMinute()])->save();

    $order = $cart->createOrder();
    gatewayPlacesOrder($order);

    expect($session->refresh()->status)->toBeInstanceOf(Completed::class)
        ->and((string) $session->order_reference)->toBe((string) $order->id);
});

it('keeps the payment method chosen before the gateway took payment', function () {
    $cart = CheckoutCart::orderable();
    CartSession::use($cart);
    $session = app(CheckoutDriver::class)->resolveOrCreateSession($cart);
    $session->forceFill(['meta' => ['payment_method' => 'stripe']])->save();

    gatewayPlacesOrder($cart->createOrder());

    $session->refresh();

    expect($session->meta['payment_method'] ?? null)->toBe('stripe')
        ->and($session->meta['completed_without_pay_step'] ?? null)->toBeTrue()
        ->and($session->cart_fingerprint)->not->toBeNull()
        ->and($session->payment_processing_at)->not->toBeNull();
});

it('never adopts an open hold-mode session', function () {
    $cart = CheckoutCart::orderable();
    CartSession::use($cart);
    $session = app(CheckoutDriver::class)->resolveOrCreateSession($cart);
    $session->forceFill(['meta' => ['payment_intent_mode' => 'hold']])->save();

    gatewayPlacesOrder($cart->createOrder());

    expect($session->refresh()->status)->toBeInstanceOf(Open::class);
});

it('lets the browser that created the intent reach the confirmation after a webhook-first completion', function () {
    Payments::extend('webhook-first-intents', fn () => app(WebhookFirstIntentGateway::class));
    app(PaymentMethodRegistry::class)->add(WebhookFirstIntentMethod::class);

    $session = CheckoutCart::session(CheckoutCart::orderable());

    $this->postJson(route('lunar.checkout.payment-intent.store', $session->uuid), [
        'payment_method' => 'webhook-first-card',
    ])->assertSuccessful();

    // The customer confirms with the gateway directly; the webhook places
    // the order before the browser's first processing poll.
    $cart = Cart::find($session->cart_reference);
    gatewayPlacesOrder($cart->createOrder());
    $session->refresh();

    expect($session->status)->toBeInstanceOf(Completed::class);

    $this->get(route('lunar.checkout.processing', $session->uuid))
        ->assertRedirect($session->success_url ?: (config('lunar.checkout.urls.success') ?: '/'))
        ->assertSessionHas('lunar.checkout.completed.order_reference', $session->order_reference);
});

it('still completes when the pay step pins the session first, without flagging it', function () {
    Log::spy();

    $cart = CheckoutCart::orderable();
    CartSession::use($cart);
    $session = app(CheckoutDriver::class)->resolveOrCreateSession($cart);

    raceTheListener(fn (CheckoutSession $s) => CheckoutSession::query()->whereKey($s->getKey())
        ->update(['status' => PaymentProcessing::$name, 'cart_fingerprint' => $s->cart_fingerprint]));

    gatewayPlacesOrder($cart->createOrder());
    $session->refresh();

    expect($session->status)->toBeInstanceOf(Completed::class)
        ->and($session->meta['completed_without_pay_step'] ?? null)->toBeNull();

    Log::shouldNotHaveReceived('warning');
});

it('leaves a session alone when another process ends it first', function () {
    Log::spy();

    $cart = CheckoutCart::orderable();
    CartSession::use($cart);
    $session = app(CheckoutDriver::class)->resolveOrCreateSession($cart);

    raceTheListener(fn (CheckoutSession $s) => CheckoutSession::query()->whereKey($s->getKey())
        ->update(['status' => Cancelled::$name]));

    gatewayPlacesOrder($cart->createOrder());

    expect($session->refresh()->status)->toBeInstanceOf(Cancelled::class);

    Log::shouldNotHaveReceived('warning');
});
