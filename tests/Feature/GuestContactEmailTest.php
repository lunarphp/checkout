<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lunar\Checkout\Contracts\CheckoutDriver;
use Lunar\Checkout\Contracts\PaymentMethodRegistry;
use Lunar\Checkout\PaymentMethods\Offline;
use Lunar\Core\Facades\CartSession;
use Lunar\Core\Models\Customer;
use Lunar\Core\Models\Order;
use Lunar\Tests\Checkout\Stubs\User;
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

/*
 * EDW2-215: the contact step must stamp the cart rows that already exist. A
 * webhook-first payment has the gateway create the order straight from the
 * cart inside authorize(), before completion's stampContactEmail() runs, so
 * the rows are the only place that order can get its email from.
 */
it('stamps the contact email on cart addresses saved before the contact step', function () {
    [$cart, $session] = guestCheckout();

    $this->post(route('lunar.checkout.contact.store', $session->uuid), ['email' => 'guest@example.com'])
        ->assertRedirect();

    $cart->refresh();

    expect($cart->shippingAddress->contact_email)->toBe('guest@example.com')
        ->and($cart->billingAddress->contact_email)->toBe('guest@example.com')
        ->and($session->refresh()->customer_email)->toBe('guest@example.com');
});

it('gives an order created straight from the cart the contact email', function () {
    [$cart, $session] = guestCheckout();

    $this->post(route('lunar.checkout.contact.store', $session->uuid), ['email' => 'guest@example.com']);

    // What a gateway's authorize() does on a webhook-first payment: no
    // completion, so nothing but the cart rows supplies the email.
    $order = $cart->refresh()->createOrder();

    expect($order->shippingAddress->contact_email)->toBe('guest@example.com')
        ->and($order->billingAddress->contact_email)->toBe('guest@example.com');
});

it('replaces an earlier contact email when the customer corrects it', function () {
    [$cart, $session] = guestCheckout();

    $this->post(route('lunar.checkout.contact.store', $session->uuid), ['email' => 'typo@example.com']);
    $this->post(route('lunar.checkout.contact.store', $session->uuid), ['email' => 'guest@example.com']);

    $cart->refresh();

    expect($cart->shippingAddress->contact_email)->toBe('guest@example.com')
        ->and($cart->billingAddress->contact_email)->toBe('guest@example.com');
});

it('treats a differently cased earlier contact email as the same one', function () {
    [$cart, $session] = guestCheckout();
    $session->forceFill(['customer_email' => 'typo@example.com'])->save();
    $cart->shippingAddress->update(['contact_email' => ' Typo@Example.com ']);

    $this->post(route('lunar.checkout.contact.store', $session->uuid), ['email' => 'guest@example.com']);

    expect($cart->refresh()->shippingAddress->contact_email)->toBe('guest@example.com');
});

it('keeps an email an address row carries of its own', function () {
    [$cart, $session] = guestCheckout();
    $cart->shippingAddress->update(['contact_email' => 'site@example.com']);

    $this->post(route('lunar.checkout.contact.store', $session->uuid), ['email' => 'guest@example.com']);

    $cart->refresh();

    expect($cart->shippingAddress->contact_email)->toBe('site@example.com')
        ->and($cart->billingAddress->contact_email)->toBe('guest@example.com');
});

it('stamps the contact email for a signed-in customer too', function () {
    [$cart, $session] = guestCheckout();
    CartSession::use($cart);

    $user = User::factory()->create();
    $customer = Customer::factory()->create();
    $user->customers()->attach($customer);

    $this->actingAs($user)
        ->post(route('lunar.checkout.contact.store', $session->uuid), ['email' => 'buyer@example.com'])
        ->assertRedirect();

    $cart->refresh();

    expect($cart->shippingAddress->contact_email)->toBe('buyer@example.com')
        ->and($cart->billingAddress->contact_email)->toBe('buyer@example.com')
        ->and($session->refresh()->customer_reference)->toBe((string) $customer->id);
});
