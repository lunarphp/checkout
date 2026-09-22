<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lunar\Checkout\PaymentMethods\StripeCardMethod;
use Lunar\Core\DataObjects\PriceValue;
use Lunar\Core\Models\Cart;
use Lunar\Core\Models\Currency;
use Lunar\Tests\Checkout\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function stripeCartTotalling(int $minor, string $code = 'GBP'): Cart
{
    $currency = Currency::query()->where('code', $code)->first()
        ?? Currency::factory()->create(['code' => $code, 'default' => true, 'decimal_places' => 2]);
    $cart = Cart::factory()->create(['currency_id' => $currency->id]);
    $cart->total = new PriceValue($minor, $currency);

    return $cart;
}

it('projects the stripe card method into the express region', function () {
    $method = new StripeCardMethod;

    expect($method->supportsExpress())->toBeTrue()
        ->and($method->expressComponent())->toBe('stripe-express');
});

it('is available for a basket at or above the currency minimum', function () {
    $method = new StripeCardMethod;

    expect($method->isAvailable(stripeCartTotalling(30)))->toBeTrue()
        ->and($method->unavailableReason(stripeCartTotalling(30)))->toBeNull();
});

it('withdraws for a basket stripe would refuse to charge', function () {
    $method = new StripeCardMethod;
    $cart = stripeCartTotalling(11);

    expect($method->isAvailable($cart))->toBeFalse()
        ->and($method->unavailableReason($cart))->toBe('Card payments need an order total of at least £0.30.');
});

it('leaves a zero total alone', function () {
    expect((new StripeCardMethod)->isAvailable(stripeCartTotalling(0)))->toBeTrue();
});

it('reads the minimum from config over the built-in table', function () {
    config()->set('lunar.checkout.stripe.minimum_amounts', ['GBP' => 500]);

    $method = new StripeCardMethod;
    $cart = stripeCartTotalling(499);

    expect($method->isAvailable($cart))->toBeFalse()
        ->and($method->unavailableReason($cart))->toBe('Card payments need an order total of at least £5.00.');
});

it('falls back to fifty minor units for an unlisted currency', function () {
    expect((new StripeCardMethod)->minimumAmount(stripeCartTotalling(0, 'XYZ')))->toBe(50);
});
