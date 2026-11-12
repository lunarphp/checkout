<?php

namespace Lunar\Tests\Checkout\Utils;

use Lunar\Core\DataObjects\PaymentIntentSnapshot;
use Lunar\Core\DataObjects\PaymentRefund;
use Lunar\Core\DataObjects\PriceValue;
use Lunar\Core\Exceptions\PaymentIntentException;
use Lunar\Core\Models\Cart;

/**
 * For a fake gateway whose test only creates intents. Every reconciliation
 * verb reports an unknowable outcome, which the checkout must leave unresolved.
 */
trait WithoutIntentReconciliation
{
    public function fetchIntent(Cart $cart, string $reference): ?PaymentIntentSnapshot
    {
        throw new PaymentIntentException('This fake gateway cannot report an intent.');
    }

    public function voidIntent(Cart $cart, string $reference): void
    {
        throw new PaymentIntentException('This fake gateway cannot void an intent.');
    }

    public function refundIntent(Cart $cart, string $reference, PriceValue $amount, string $idempotencyKey): PaymentRefund
    {
        throw new PaymentIntentException('This fake gateway cannot refund an intent.');
    }
}
