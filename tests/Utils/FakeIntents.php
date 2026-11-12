<?php

namespace Lunar\Tests\Checkout\Utils;

use Lunar\Core\DataObjects\PaymentIntentSnapshot;
use Lunar\Core\DataObjects\PriceValue;
use Lunar\Core\Enums\PaymentIntentStatus;
use Lunar\Core\Models\Cart;

final class FakeIntents
{
    public static function snapshot(Cart $cart, PaymentIntentStatus $status, int $amount = 0, ?string $walletLabel = null): PaymentIntentSnapshot
    {
        $price = fn (int $value) => new PriceValue($value, $cart->currency);

        return new PaymentIntentSnapshot(
            status: $status,
            amount: $price($amount),
            captured: $price($status === PaymentIntentStatus::Captured ? $amount : 0),
            refunded: $price(0),
            walletLabel: $walletLabel,
        );
    }
}
