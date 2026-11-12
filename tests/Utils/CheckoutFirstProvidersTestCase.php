<?php

namespace Lunar\Tests\Checkout\Utils;

use Lunar\Checkout\CheckoutServiceProvider;
use Lunar\Core\LunarServiceProvider;
use Lunar\Tests\Checkout\TestCase;

/**
 * Lists the checkout provider ahead of core's, the order an app's provider
 * discovery can produce, so registration that depends on core being
 * registered first fails here.
 */
class CheckoutFirstProvidersTestCase extends TestCase
{
    protected function getPackageProviders($app)
    {
        $providers = parent::getPackageProviders($app);

        return [
            CheckoutServiceProvider::class,
            ...array_values(array_filter($providers, fn (string $provider): bool => $provider !== CheckoutServiceProvider::class && $provider !== LunarServiceProvider::class)),
            LunarServiceProvider::class,
        ];
    }
}
