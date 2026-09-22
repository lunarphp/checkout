<?php

namespace Lunar\Checkout\DeliveryCountries;

use Illuminate\Support\Collection;
use Lunar\Checkout\Contracts\DeliveryCountries;
use Lunar\Core\Models\Cart;
use Lunar\Core\Models\Country;
use Lunar\Shipping\Models\ShippingZone;

/**
 * Derives the delivery countries from table-rate shipping zones: a country is
 * deliverable when some zone covering it carries a rate for an enabled
 * shipping method. Country, state and postcode zones all name their
 * countries; an unrestricted zone with a live rate means everywhere.
 *
 * Bound over {@see ConfiguredCountries} only when lunarphp/table-rate-shipping
 * is installed, so the checkout stays usable without it. The binding lives
 * here rather than in the shipping package because nothing in the Lunar
 * monorepo may depend on this one.
 */
class ShippingZoneCountries implements DeliveryCountries
{
    public function available(Cart $cart): Collection
    {
        $zones = ShippingZone::query()
            ->whereHas('rates.shippingMethod', fn ($query) => $query->where('enabled', true))
            ->with('countries', 'states.country')
            ->get();

        if ($zones->contains(fn (ShippingZone $zone): bool => $zone->type === 'unrestricted')) {
            return Country::query()->orderBy('name')->get();
        }

        return $zones
            ->flatMap(fn (ShippingZone $zone): Collection => $zone->countries->merge(
                $zone->states->map(fn ($state) => $state->country)->filter(),
            ))
            ->unique('id')
            ->sortBy('name')
            ->values();
    }
}
