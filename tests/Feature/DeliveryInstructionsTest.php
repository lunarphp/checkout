<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lunar\Checkout\Contracts\PaymentMethodRegistry;
use Lunar\Checkout\PaymentMethods\Offline;
use Lunar\Core\Facades\CartSession;
use Lunar\Core\Models\Order;
use Lunar\Tests\Checkout\TestCase;
use Lunar\Tests\Checkout\Utils\CheckoutCart;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Delivery instructions ("leave with the neighbour", "gate code 1234") ride on
 * the delivery address only: Lunar's address tables carry the column on cart,
 * order and saved addresses alike, and the order copies the cart row.
 */
function instructionsCheckout(): array
{
    $cart = CheckoutCart::orderable();
    $session = CheckoutCart::session($cart);
    CartSession::use($cart);

    return [$cart, $session];
}

function instructionsAddress(array $overrides = []): array
{
    return [
        'first_name' => 'Terry',
        'last_name' => 'Sparks',
        'line1' => '1 Trade Counter Way',
        'city' => 'London',
        'postcode' => 'SE1 1AA',
        'country_code' => 'GB',
        ...$overrides,
    ];
}

it('saves delivery instructions on the delivery address and projects them back', function () {
    [$cart, $session] = instructionsCheckout();

    $this->post(route('lunar.checkout.shipping-address.store', $session->uuid), instructionsAddress([
        'delivery_instructions' => 'Leave at the side gate',
    ]))->assertRedirect()->assertSessionHasNoErrors();

    expect($cart->refresh()->shippingAddress->delivery_instructions)->toBe('Leave at the side gate');

    $this->get(route('lunar.checkout.show', $session->uuid), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.checkout.shippingAddress.deliveryInstructions', 'Leave at the side gate');
});

it('clears delivery instructions when the address is saved without them', function () {
    [$cart, $session] = instructionsCheckout();

    $this->post(route('lunar.checkout.shipping-address.store', $session->uuid), instructionsAddress([
        'delivery_instructions' => 'Leave at the side gate',
    ]));
    $this->post(route('lunar.checkout.shipping-address.store', $session->uuid), instructionsAddress());

    expect($cart->refresh()->shippingAddress->delivery_instructions)->toBeNull();
});

it('refuses delivery instructions over 1000 characters', function () {
    [$cart, $session] = instructionsCheckout();

    $this->post(route('lunar.checkout.shipping-address.store', $session->uuid), instructionsAddress([
        'delivery_instructions' => str_repeat('a', 1001),
    ]))->assertSessionHasErrors('delivery_instructions');
});

it('never puts delivery instructions on the billing address', function () {
    [$cart, $session] = instructionsCheckout();

    $this->post(route('lunar.checkout.billing-address.store', $session->uuid), instructionsAddress([
        'delivery_instructions' => 'Leave at the side gate',
    ]));

    expect($cart->refresh()->billingAddress->delivery_instructions)->toBeNull();
});

it('carries delivery instructions onto the placed order\'s shipping address', function () {
    app(PaymentMethodRegistry::class)->add(Offline::class);
    [, $session] = instructionsCheckout();

    $this->post(route('lunar.checkout.shipping-address.store', $session->uuid), instructionsAddress([
        'delivery_instructions' => 'Ring the bell twice',
    ]));
    // The address edit re-quotes, so the option the cart was orderable with
    // is still on it; pay against the refreshed fingerprint.
    $this->postJson(route('lunar.checkout.pay', $session->uuid), [
        'fingerprint' => CheckoutCart::fingerprint($session->refresh()),
        'payment_method' => 'offline',
    ])->assertSuccessful();

    $order = Order::query()->findOrFail((int) $session->refresh()->order_reference);

    expect($order->shippingAddress->delivery_instructions)->toBe('Ring the bell twice')
        ->and($order->billingAddress->delivery_instructions)->toBeNull();
});
