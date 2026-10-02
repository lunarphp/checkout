<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lunar\Checkout\Contracts\CheckoutDriver;
use Lunar\Checkout\Contracts\PaymentMethodRegistry;
use Lunar\Checkout\PaymentMethods\Offline;
use Lunar\Core\Models\Order;
use Lunar\Tests\Checkout\TestCase;
use Lunar\Tests\Checkout\Utils\CheckoutCart;

uses(TestCase::class, RefreshDatabase::class);

/**
 * A guest's email lives on the session after the contact step. Lunar rewrites
 * an address row wholesale and the address forms never send an email, so the
 * address steps must carry it over, or the placed order has nobody to write to.
 */
function guestCheckout(): array
{
    $cart = CheckoutCart::orderable();
    $cart->addresses()->update(['contact_email' => null]);
    $session = CheckoutCart::session($cart->refresh());

    return [$cart, $session];
}

function addressPayload(): array
{
    return [
        'first_name' => 'Terry',
        'last_name' => 'Sparks',
        'line1' => '1 Trade Counter Way',
        'city' => 'London',
        'postcode' => 'SE1 1AA',
        'country_code' => 'GB',
    ];
}

it('keeps the contact email when the shipping address is saved after the contact step', function () {
    [$cart, $session] = guestCheckout();

    $this->post(route('lunar.checkout.contact.store', $session->uuid), ['email' => 'guest@example.com'])
        ->assertRedirect();
    $this->post(route('lunar.checkout.shipping-address.store', $session->uuid), addressPayload())
        ->assertRedirect();

    expect($cart->refresh()->shippingAddress->contact_email)->toBe('guest@example.com');
});

it('puts the contact email on the billing address', function () {
    [$cart, $session] = guestCheckout();

    $this->post(route('lunar.checkout.contact.store', $session->uuid), ['email' => 'guest@example.com']);
    $this->post(route('lunar.checkout.billing-address.store', $session->uuid), addressPayload());

    expect($cart->refresh()->billingAddress->contact_email)->toBe('guest@example.com');
});

it('prefers an email the address itself carries, as a wallet sheet sends', function () {
    [$cart, $session] = guestCheckout();

    $this->post(route('lunar.checkout.contact.store', $session->uuid), ['email' => 'guest@example.com']);
    app(CheckoutDriver::class)->storeShippingAddress($session->refresh(), [
        ...addressPayload(),
        'email' => 'site@example.com',
    ]);

    expect($cart->refresh()->shippingAddress->contact_email)->toBe('site@example.com');
});

it('places a guest order whose addresses carry the contact email', function () {
    app(PaymentMethodRegistry::class)->add(Offline::class);
    [, $session] = guestCheckout();

    // Only the session knows the email: no address step ran after contact.
    $this->post(route('lunar.checkout.contact.store', $session->uuid), ['email' => 'guest@example.com']);

    $this->postJson(route('lunar.checkout.pay', $session->uuid), [
        'fingerprint' => CheckoutCart::fingerprint($session->refresh()),
        'payment_method' => 'offline',
    ])->assertSuccessful();

    $order = Order::query()->findOrFail((int) $session->refresh()->order_reference);

    expect($order->shippingAddress->contact_email)->toBe('guest@example.com')
        ->and($order->billingAddress->contact_email)->toBe('guest@example.com');
});
