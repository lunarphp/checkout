<?php

use Lunar\Checkout\Validation\Cart\PickupPointRequired;
use Lunar\Core\Enums\StepList;
use Lunar\Core\Facades\Steps;
use Lunar\Core\Validation\Cart\ValidateCartForOrderCreation;
use Lunar\Tests\Checkout\Utils\CheckoutFirstProvidersTestCase;

uses(CheckoutFirstProvidersTestCase::class);

it('registers the pickup point validator when the checkout provider is listed before core', function () {
    expect(Steps::for(StepList::OrderCreateValidation)->all())
        ->toBe([ValidateCartForOrderCreation::class, PickupPointRequired::class]);
});
