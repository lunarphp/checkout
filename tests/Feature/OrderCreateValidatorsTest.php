<?php

use Lunar\Checkout\CheckoutServiceProvider;
use Lunar\Checkout\Validation\Cart\PickupPointRequired;
use Lunar\Core\Enums\StepList;
use Lunar\Core\Facades\Steps;
use Lunar\Core\Validation\Cart\ValidateCartForOrderCreation;
use Lunar\Tests\Checkout\TestCase;

uses(TestCase::class);

it('registers the pickup point validator after core\'s own', function () {
    expect(Steps::for(StepList::OrderCreateValidation)->all())
        ->toBe([ValidateCartForOrderCreation::class, PickupPointRequired::class]);
});

it('registers through the step registry and leaves the removed config key unset', function () {
    expect(config()->has('lunar.cart.validators'))->toBeFalse();
});

it('registers the validator once when the provider boots again', function () {
    app()->getProvider(CheckoutServiceProvider::class)->boot();

    expect(Steps::for(StepList::OrderCreateValidation)->all())
        ->toBe([ValidateCartForOrderCreation::class, PickupPointRequired::class]);
});
