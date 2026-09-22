<?php

namespace Lunar\Checkout\Contracts;

use Illuminate\Support\Collection;
use Lunar\Checkout\DeliveryCountries\ConfiguredCountries;
use Lunar\Checkout\DeliveryCountries\ShippingZoneCountries;
use Lunar\Core\Models\Cart;
use Lunar\Core\Models\Country;

/**
 * Supplies the countries a cart may be delivered to (spec 0011 §H). The
 * delivery step renders exactly this list and the shipping-address store
 * refuses anything outside it, so a customer never saves an address the
 * store cannot ship to and then finds no delivery options.
 *
 * The package binds {@see ShippingZoneCountries} when
 * lunarphp/table-rate-shipping is installed and {@see ConfiguredCountries}
 * otherwise. Both bindings are made here: no package in the Lunar monorepo
 * may depend on this one, so a shipping package cannot rebind it itself.
 * A host with its own country source binds this interface in its own
 * service provider.
 */
interface DeliveryCountries
{
    /**
     * @return Collection<int, Country>
     */
    public function available(Cart $cart): Collection;
}
