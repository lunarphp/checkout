<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lunar\Checkout\Contracts\PaymentMethodRegistry;
use Lunar\Checkout\Models\CheckoutSession;
use Lunar\Checkout\PaymentMethods\AbstractPaymentMethod;
use Lunar\Checkout\States\CheckoutSession\PaymentProcessing;
use Lunar\Core\Contracts\SupportsPaymentHolds;
use Lunar\Core\Contracts\SyncsPaymentIntents;
use Lunar\Core\DataObjects\PaymentIntentDescriptor;
use Lunar\Core\DataObjects\PriceValue;
use Lunar\Core\Enums\HoldAdjustment;
use Lunar\Core\Facades\Payments;
use Lunar\Core\Models\Cart;
use Lunar\Core\Models\Price;
use Lunar\Core\PaymentTypes\OfflinePayment;
use Lunar\Tests\Checkout\TestCase;
use Lunar\Tests\Checkout\Utils\CheckoutCart;
use Lunar\Tests\Checkout\Utils\WithoutIntentReconciliation;
use Spatie\LaravelBlink\BlinkFacade as Blink;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Records the cart total each gateway verb is handed, the figure a real
 * gateway would charge.
 */
class FreshTotalsGateway extends OfflinePayment implements SupportsPaymentHolds, SyncsPaymentIntents
{
    use WithoutIntentReconciliation;

    /** @var array<string, int|null> */
    public static array $seen = [];

    public function createIntent(Cart $cart): PaymentIntentDescriptor
    {
        static::$seen['createIntent'] = $cart->total?->value;

        return new PaymentIntentDescriptor('pi_fresh_'.$cart->id);
    }

    public function syncIntent(Cart $cart): void
    {
        static::$seen['syncIntent'] = $cart->total?->value;
    }

    public function createHold(Cart $cart): PaymentIntentDescriptor
    {
        static::$seen['createHold'] = $cart->total?->value;

        return new PaymentIntentDescriptor('hold_fresh_'.$cart->id);
    }

    public function adjustHold(Cart $cart, string $reference, PriceValue $amount): HoldAdjustment
    {
        static::$seen['adjustHold'] = $amount->value;

        return HoldAdjustment::Ok;
    }

    public function captureHold(Cart $cart, string $reference, PriceValue $amount): void {}
}

class FreshTotalsMethod extends AbstractPaymentMethod
{
    public function handle(): string
    {
        return 'fresh-card';
    }

    public function label(): string
    {
        return 'Card';
    }

    public function driver(): string
    {
        return 'fresh-totals';
    }

    public function requiresIntent(): bool
    {
        return true;
    }

    public function component(): string
    {
        return 'stripe-card';
    }
}

beforeEach(function () {
    FreshTotalsGateway::$seen = [];

    Payments::extend('fresh-totals', fn () => app(FreshTotalsGateway::class));
    app(PaymentMethodRegistry::class)->add(FreshTotalsMethod::class);
});

function calculatedSession(): CheckoutSession
{
    $cart = CheckoutCart::orderable(unitPrice: 1000);
    $session = CheckoutCart::session($cart);

    expect($cart->total->value)->toBe(1000)
        ->and($session->amount_total)->toBe(1000);

    return $session;
}

/**
 * Reprices the cart's variant straight in the database, which bumps no cart
 * revision. Blink is flushed so the next request starts from the persisted
 * totals alone, as a new production request would.
 */
function repriceSessionCart(CheckoutSession $session, int $price): void
{
    $variantId = Cart::query()->with('lines')->findOrFail((int) $session->cart_reference)->lines->first()->purchasable_id;

    DB::table((new Price)->getTable())
        ->where('priceable_id', $variantId)
        ->update(['price' => $price]);

    Blink::flush();
}

it('creates the intent and pins the session from the repriced total, not the cached one', function () {
    $session = calculatedSession();
    repriceSessionCart($session, 2500);

    $this->postJson(route('lunar.checkout.payment-intent.store', $session->uuid), [
        'payment_method' => 'fresh-card',
    ])->assertOk();

    expect(FreshTotalsGateway::$seen['createIntent'] ?? null)->toBe(2500);

    repriceSessionCart($session, 3000);

    $this->postJson(route('lunar.checkout.pay', $session->uuid), [
        'payment_method' => 'fresh-card',
        'fingerprint' => CheckoutCart::fingerprint($session),
    ])->assertSuccessful();

    $session->refresh();

    expect($session->status)->toBeInstanceOf(PaymentProcessing::class)
        ->and($session->amount_total)->toBe(3000)
        ->and(FreshTotalsGateway::$seen['syncIntent'] ?? null)->toBe(3000);
});

it('creates the hold and pins the session from the repriced total, not the cached one', function () {
    $session = calculatedSession();
    repriceSessionCart($session, 2500);

    $this->postJson(route('lunar.checkout.payment-intent.store', $session->uuid), [
        'payment_method' => 'fresh-card',
        'mode' => 'hold',
    ])->assertOk();

    expect(FreshTotalsGateway::$seen['createHold'] ?? null)->toBe(2500);

    repriceSessionCart($session, 3000);

    $this->postJson(route('lunar.checkout.pay', $session->uuid), [
        'payment_method' => 'fresh-card',
        'fingerprint' => CheckoutCart::fingerprint($session),
    ])->assertSuccessful();

    $session->refresh();

    expect($session->status)->toBeInstanceOf(PaymentProcessing::class)
        ->and($session->amount_total)->toBe(3000)
        ->and(FreshTotalsGateway::$seen['adjustHold'] ?? null)->toBe(3000);
});
