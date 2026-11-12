<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Lunar\Checkout\Contracts\CheckoutDriver;
use Lunar\Checkout\Contracts\PaymentMethodRegistry;
use Lunar\Checkout\Events\CheckoutCompletionFailed;
use Lunar\Checkout\Exceptions\PaymentConfirmationException;
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

/**
 * A credit-limit style method: offered only while the basket stays within a
 * limit, the way a host's on-account closure decides.
 */
class FreshLimitedAccountMethod extends AbstractPaymentMethod
{
    public function handle(): string
    {
        return 'fresh-account';
    }

    public function label(): string
    {
        return 'Pay on account';
    }

    public function driver(): string
    {
        return 'offline';
    }

    public function component(): string
    {
        return 'offline-notice';
    }

    public function isAvailable(Cart $cart): bool
    {
        return $cart->calculate()->total->value <= 2000;
    }
}

class FreshLimitedCardMethod extends FreshTotalsMethod
{
    public function handle(): string
    {
        return 'fresh-limited-card';
    }

    public function isAvailable(Cart $cart): bool
    {
        return $cart->calculate()->total->value <= 2000;
    }
}

beforeEach(function () {
    FreshTotalsGateway::$seen = [];

    Payments::extend('fresh-totals', fn () => app(FreshTotalsGateway::class));
    app(PaymentMethodRegistry::class)
        ->add(FreshTotalsMethod::class)
        ->add(FreshLimitedAccountMethod::class)
        ->add(FreshLimitedCardMethod::class);
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

/**
 * The cart's live price is $live while its persisted totals still say the
 * session's 1000, which calculate() serves. Returns the fingerprint of the
 * live basket, the one a customer looking at fresh totals would confirm.
 */
function staleCachedTotals(CheckoutSession $session, int $live): string
{
    repriceSessionCart($session, $live);
    $fingerprint = CheckoutCart::fingerprint($session);

    repriceSessionCart($session, 1000);
    Cart::query()->findOrFail((int) $session->cart_reference)->recalculate();

    repriceSessionCart($session, $live);

    $cached = Cart::query()->findOrFail((int) $session->cart_reference);
    expect($cached->totalsAreFresh())->toBeTrue()
        ->and($cached->calculate()->total->value)->toBe(1000);
    Blink::flush();

    return $fingerprint;
}

it('decides payment method availability at pay on the repriced total, not the cached one', function () {
    $session = calculatedSession();
    $fingerprint = staleCachedTotals($session, 2500);

    $this->postJson(route('lunar.checkout.pay', $session->uuid), [
        'payment_method' => 'fresh-account',
        'fingerprint' => $fingerprint,
    ])->assertUnprocessable()->assertJsonValidationErrors(['payment_method']);

    expect($session->refresh()->order_reference)->toBeNull();
});

it('decides payment method availability for a new intent on the repriced total, not the cached one', function () {
    $session = calculatedSession();
    staleCachedTotals($session, 2500);

    $this->postJson(route('lunar.checkout.payment-intent.store', $session->uuid), [
        'payment_method' => 'fresh-limited-card',
    ])->assertUnprocessable()->assertJsonValidationErrors(['payment_method']);

    expect(FreshTotalsGateway::$seen)->not->toHaveKey('createIntent');
});

/**
 * The persisted totals still describe the old price, so anything that trusts
 * calculate() would see the basket the customer confirmed.
 */
function expectCachedTotalsServable(CheckoutSession $session, int $cachedTotal): void
{
    $cart = Cart::query()->findOrFail((int) $session->cart_reference);

    expect($cart->totalsAreFresh())->toBeTrue()
        ->and($cart->calculate()->total->value)->toBe($cachedTotal);

    Blink::flush();
}

it('refuses to pin a session whose cart was repriced behind fresh cached totals', function () {
    $session = calculatedSession();
    $fingerprint = CheckoutCart::fingerprint($session);

    repriceSessionCart($session, 2500);
    expectCachedTotalsServable($session, 1000);

    expect(fn () => app(CheckoutDriver::class)->assertReadyForPayment($session, $fingerprint))
        ->toThrow(PaymentConfirmationException::class, 'fingerprint_mismatch');

    expect($session->refresh()->status)->not->toBeInstanceOf(PaymentProcessing::class)
        ->and($session->amount_total)->toBe(1000);
});

it('refuses to complete a pinned session whose cart was repriced behind fresh cached totals', function () {
    Event::fake([CheckoutCompletionFailed::class]);

    $session = calculatedSession();
    $driver = app(CheckoutDriver::class);
    $driver->assertReadyForPayment($session, CheckoutCart::fingerprint($session));
    expect($session->status)->toBeInstanceOf(PaymentProcessing::class);

    repriceSessionCart($session, 2500);
    expectCachedTotalsServable($session, 1000);

    expect(fn () => $driver->complete($session))
        ->toThrow(PaymentConfirmationException::class, 'fingerprint_mismatch');

    Event::assertDispatched(CheckoutCompletionFailed::class, fn (CheckoutCompletionFailed $event): bool => $event->reason === 'verify-mismatch');
    expect($session->refresh()->order_reference)->toBeNull();
});

it('returns the repriced snapshot when a customer is associated, not the cached one', function () {
    $session = calculatedSession();

    repriceSessionCart($session, 2500);
    expectCachedTotalsServable($session, 1000);

    $snapshot = app(CheckoutDriver::class)->associateCustomer($session, '42', 'terry@example.com');

    expect($snapshot->amountTotal)->toBe(2500)
        ->and($snapshot->fingerprint)->toBe(CheckoutCart::fingerprint($session));
});
