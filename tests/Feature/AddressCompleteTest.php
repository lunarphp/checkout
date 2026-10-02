<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lunar\Core\Facades\CartSession;
use Lunar\Core\Models\Country;
use Lunar\Tests\Checkout\TestCase;
use Lunar\Tests\Checkout\Utils\CheckoutCart;

uses(TestCase::class, RefreshDatabase::class);

/**
 * A storefront may keep only a postcode on the cart (a basket delivery
 * estimate), which the delivery step should prefill, not treat as a
 * finished address: the shipping step unlocks on a deliverable address.
 */
function addressCompleteFor(?array $shippingAddress): bool
{
    $cart = CheckoutCart::orderable();
    $cart->addresses()->where('type', 'shipping')->delete();

    if ($shippingAddress !== null) {
        $cart->addresses()->create([
            'type' => 'shipping',
            'country_id' => Country::query()->where('iso2', 'GB')->value('id'),
            ...$shippingAddress,
        ]);
    }

    $session = CheckoutCart::session($cart->refresh());
    CartSession::use($cart);

    return test()->get(route('lunar.checkout.show', $session->uuid), ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.checkout.addressComplete');
}

it('treats a postcode alone as an unfinished address', function () {
    expect(addressCompleteFor(['postcode' => 'SE1 1AA']))->toBeFalse();
});

it('treats an address with a first line and postcode as complete', function () {
    expect(addressCompleteFor([
        'first_name' => 'Terry',
        'line_one' => '1 Trade Counter Way',
        'city' => 'London',
        'postcode' => 'SE1 1AA',
    ]))->toBeTrue();
});

it('has no complete address before one is given', function () {
    expect(addressCompleteFor(null))->toBeFalse();
});
