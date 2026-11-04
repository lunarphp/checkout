<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Lunar\Checkout\Contracts\CheckoutDriver;
use Lunar\Checkout\Contracts\PaymentMethodRegistry;
use Lunar\Checkout\Events\CheckoutCompletionFailed;
use Lunar\Checkout\Exceptions\CheckoutSessionConflictException;
use Lunar\Checkout\Exceptions\CheckoutSessionNotOperableException;
use Lunar\Checkout\Models\CheckoutSession;
use Lunar\Checkout\PaymentMethods\AbstractPaymentMethod;
use Lunar\Checkout\States\CheckoutSession\Completed;
use Lunar\Checkout\States\CheckoutSession\Open;
use Lunar\Checkout\States\CheckoutSession\PaymentProcessing;
use Lunar\Core\Contracts\CreatesPaymentIntents;
use Lunar\Core\Contracts\SupportsPaymentIntents;
use Lunar\Core\DataObjects\PaymentAuthorize;
use Lunar\Core\DataObjects\PaymentIntentDescriptor;
use Lunar\Core\DataObjects\PaymentRefund;
use Lunar\Core\Enums\PaymentIntentStatus;
use Lunar\Core\Facades\CartSession;
use Lunar\Core\Facades\Payments;
use Lunar\Core\Models\Cart;
use Lunar\Core\Models\Order;
use Lunar\Core\PaymentTypes\OfflinePayment;
use Lunar\Tests\Checkout\TestCase;
use Lunar\Tests\Checkout\Utils\CheckoutCart;

uses(TestCase::class, RefreshDatabase::class);

/**
 * A card gateway that records captured money as a Transaction row on the
 * order it authorises against, the way Stripe's UpdateOrderFromIntent does.
 */
class DraftAtPinGateway extends OfflinePayment implements CreatesPaymentIntents, SupportsPaymentIntents
{
    /** @var array<int, string> */
    public static array $refunds = [];

    public function createIntent(Cart $cart): PaymentIntentDescriptor
    {
        return new PaymentIntentDescriptor('pi_draft_'.$cart->id);
    }

    public function fetchIntent(string $reference): PaymentIntentStatus
    {
        return PaymentIntentStatus::Captured;
    }

    public function voidIntent(string $reference): void {}

    public function refundIntent(string $reference, int $amountMinor, string $idempotencyKey): PaymentRefund
    {
        static::$refunds[] = $reference;

        return new PaymentRefund(success: true, reference: 'refund_'.$reference);
    }

    public function authorize(): ?PaymentAuthorize
    {
        if (! $this->order) {
            $this->order = $this->cart->draftOrder()->first() ?: $this->cart->createOrder();
        }

        $this->order->transactions()->create([
            'success' => true,
            'type' => 'capture',
            'driver' => 'draft-at-pin',
            'amount' => (int) $this->order->total,
            'reference' => $this->data['payment_intent'],
            'status' => 'succeeded',
        ]);

        return parent::authorize();
    }
}

class DraftAtPinCardMethod extends AbstractPaymentMethod
{
    public function handle(): string
    {
        return 'draft-card';
    }

    public function label(): string
    {
        return 'Card';
    }

    public function driver(): string
    {
        return 'draft-at-pin';
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

class DraftAtPinBankTransferMethod extends DraftAtPinCardMethod
{
    public function handle(): string
    {
        return 'draft-bank-transfer';
    }

    public function driver(): string
    {
        return 'offline';
    }

    public function requiresIntent(): bool
    {
        return false;
    }

    public function component(): string
    {
        return 'offline-notice';
    }
}

beforeEach(function () {
    DraftAtPinGateway::$refunds = [];

    Payments::extend('draft-at-pin', fn () => app(DraftAtPinGateway::class));
    app(PaymentMethodRegistry::class)->add(DraftAtPinCardMethod::class);
    app(PaymentMethodRegistry::class)->add(DraftAtPinBankTransferMethod::class);
});

function draftAtPinSession(): CheckoutSession
{
    $cart = CheckoutCart::orderable();
    CartSession::use($cart);
    $session = CheckoutCart::session($cart);

    test()->postJson(route('lunar.checkout.payment-intent.store', $session->uuid), [
        'payment_method' => 'draft-card',
    ])->assertSuccessful();

    return $session->refresh();
}

function pressPay(CheckoutSession $session, string $method = 'draft-card'): TestResponse
{
    return test()->postJson(route('lunar.checkout.pay', $session->uuid), [
        'fingerprint' => CheckoutCart::fingerprint($session),
        'payment_method' => $method,
    ]);
}

function sessionCart(CheckoutSession $session): Cart
{
    return Cart::query()->findOrFail((int) $session->cart_reference);
}

/**
 * Changes the cart after the pin. Rebuilding a draft whose line quantity
 * changed lazy-loads the order line's order inside core's OrderLineQuantity
 * validator, which this suite's strict mode rejects, so the retry tests
 * allow lazy loading.
 */
function doubleTheQuantity(CheckoutSession $session): void
{
    Model::preventLazyLoading(false);

    sessionCart($session)->lines()->firstOrFail()->update(['quantity' => 2]);
}

function orderedQuantity(Order $order): int
{
    return (int) $order->lines()->where('type', '!=', 'shipping')->sum('quantity');
}

it('drafts exactly one unplaced order for the cart when Pay pins a gateway method', function () {
    $session = draftAtPinSession();

    pressPay($session)->assertSuccessful()->assertJsonPath('pinned', true);

    $order = Order::query()->sole();

    expect($session->refresh()->status)->toBeInstanceOf(PaymentProcessing::class)
        ->and($order->cart_id)->toBe((int) $session->cart_reference)
        ->and($order->placed_at)->toBeNull()
        ->and($order->transactions()->count())->toBe(0);
});

it('drafts nothing when the cart changed since the customer confirmed it', function () {
    $session = draftAtPinSession();
    $staleToken = CheckoutCart::fingerprint($session);

    CheckoutCart::addLine(sessionCart($session));

    $this->postJson(route('lunar.checkout.pay', $session->uuid), [
        'fingerprint' => $staleToken,
        'payment_method' => 'draft-card',
    ])->assertUnprocessable();

    expect($session->refresh()->status)->toBeInstanceOf(Open::class)
        ->and(Order::query()->count())->toBe(0);
});

it('drafts nothing when the session expired before the pin', function () {
    $session = draftAtPinSession();
    $session->forceFill(['expires_at' => now()->subMinute()])->save();

    expect(fn () => app(CheckoutDriver::class)->assertReadyForPayment($session, CheckoutCart::fingerprint($session)))
        ->toThrow(CheckoutSessionNotOperableException::class);

    expect(Order::query()->count())->toBe(0);
});

it('drafts no second order when a concurrent Pay loses the pin', function () {
    $session = draftAtPinSession();
    $token = CheckoutCart::fingerprint($session);

    app(CheckoutDriver::class)->assertReadyForPayment($session, $token);
    $draftId = Order::query()->sole()->id;

    // The losing submission loaded the session while it was still Open.
    $loser = $session->replicate()->forceFill(['id' => $session->id, 'status' => Open::$name]);
    $loser->exists = true;

    expect(fn () => app(CheckoutDriver::class)->assertReadyForPayment($loser, $token))
        ->toThrow(CheckoutSessionConflictException::class);

    expect(Order::query()->pluck('id')->all())->toBe([$draftId]);
});

it('leaves the session open when the draft cannot be built', function () {
    $session = draftAtPinSession();

    config()->set('lunar.orders.pipelines.creation', [
        fn () => throw new RuntimeException('order pipeline down'),
    ]);

    expect(fn () => app(CheckoutDriver::class)->assertReadyForPayment($session, CheckoutCart::fingerprint($session)))
        ->toThrow(RuntimeException::class, 'order pipeline down');

    $session->refresh();

    expect($session->status)->toBeInstanceOf(Open::class)
        ->and($session->payment_processing_at)->toBeNull()
        ->and(Order::query()->count())->toBe(0);
});

it('places the drafted order on completion and records the payment on it', function () {
    $session = draftAtPinSession();
    pressPay($session)->assertSuccessful();
    $draftId = Order::query()->sole()->id;

    $this->get(route('lunar.checkout.processing', $session->uuid));

    $session->refresh();
    $order = Order::query()->sole();

    expect($session->status)->toBeInstanceOf(Completed::class)
        ->and($order->id)->toBe($draftId)
        ->and((string) $session->order_reference)->toBe((string) $draftId)
        ->and($order->placed_at)->not->toBeNull()
        ->and($order->transactions()->where('type', 'capture')->pluck('reference')->all())->toBe([$session->payment_intent_ref]);
});

it('places the drafted order without rebuilding it once the payment is captured', function () {
    $session = draftAtPinSession();
    pressPay($session)->assertSuccessful();
    $draftId = Order::query()->sole()->id;

    config()->set('lunar.orders.pipelines.creation', [
        fn () => throw new RuntimeException('order pipeline down'),
    ]);

    $this->get(route('lunar.checkout.processing', $session->uuid));

    expect($session->refresh()->status)->toBeInstanceOf(Completed::class)
        ->and((string) $session->order_reference)->toBe((string) $draftId)
        ->and(Order::query()->sole()->placed_at)->not->toBeNull()
        ->and(DraftAtPinGateway::$refunds)->toBeEmpty();
});

it('places the drafted order when the webhook arrives before the browser', function () {
    $session = draftAtPinSession();
    pressPay($session)->assertSuccessful();
    $draftId = Order::query()->sole()->id;

    Payments::driver('draft-at-pin')
        ->cart(sessionCart($session)->calculate())
        ->withData(['payment_intent' => $session->refresh()->payment_intent_ref])
        ->authorize();

    $session->refresh();
    $order = Order::query()->sole();

    expect($session->status)->toBeInstanceOf(Completed::class)
        ->and($order->id)->toBe($draftId)
        ->and((string) $session->order_reference)->toBe((string) $draftId)
        ->and($order->placed_at)->not->toBeNull()
        ->and($order->transactions()->count())->toBe(1);
});

it('keeps the draft unplaced after a refund and reuses it on the next Pay', function () {
    Event::fake([CheckoutCompletionFailed::class]);

    $session = draftAtPinSession();
    pressPay($session)->assertSuccessful();
    $draftId = Order::query()->sole()->id;

    // The cart changes during the gateway window: completion refunds.
    doubleTheQuantity($session);
    $this->get(route('lunar.checkout.processing', $session->uuid));

    expect(DraftAtPinGateway::$refunds)->toHaveCount(1)
        ->and($session->refresh()->status)->toBeInstanceOf(Open::class)
        ->and(Order::query()->sole()->id)->toBe($draftId)
        ->and(Order::query()->sole()->placed_at)->toBeNull();

    pressPay($session)->assertSuccessful();

    $order = Order::query()->sole();

    expect($order->id)->toBe($draftId)
        ->and($order->placed_at)->toBeNull()
        ->and(orderedQuantity($order))->toBe(2);
});

it('places the refreshed draft when the retry after a refund pays synchronously', function () {
    Event::fake([CheckoutCompletionFailed::class]);

    $session = draftAtPinSession();
    pressPay($session)->assertSuccessful();
    $draftId = Order::query()->sole()->id;

    doubleTheQuantity($session);
    $this->get(route('lunar.checkout.processing', $session->uuid));

    expect($session->refresh()->status)->toBeInstanceOf(Open::class);

    pressPay($session, 'draft-bank-transfer')->assertSuccessful()->assertJsonPath('completed', true);

    $order = Order::query()->sole();

    expect($order->id)->toBe($draftId)
        ->and($order->placed_at)->not->toBeNull()
        ->and(orderedQuantity($order))->toBe(2)
        ->and((int) $order->total)->toBe((int) sessionCart($session)->calculate()->total->value);
});

it('rebuilds a stale draft when a payment arrives on a session pinned without the gate', function () {
    Event::fake([CheckoutCompletionFailed::class]);

    $session = draftAtPinSession();
    pressPay($session)->assertSuccessful();
    $draftId = Order::query()->sole()->id;

    doubleTheQuantity($session);
    $this->get(route('lunar.checkout.processing', $session->uuid));

    expect($session->refresh()->status)->toBeInstanceOf(Open::class);

    // The gateway took payment with no Pay step: the session is pinned
    // against the live cart the way the webhook listener pins it, and the
    // draft still holds the cart from the first attempt.
    $session->forceFill([
        'status' => PaymentProcessing::$name,
        'payment_intent_ref' => 'pi_without_pay',
        'payment_processing_at' => now(),
        'cart_fingerprint' => CheckoutCart::fingerprint($session),
        'amount_total' => sessionCart($session)->calculate()->total->value,
    ])->save();

    $this->get(route('lunar.checkout.processing', $session->uuid));

    $order = Order::query()->sole();

    expect($session->refresh()->status)->toBeInstanceOf(Completed::class)
        ->and($order->id)->toBe($draftId)
        ->and($order->placed_at)->not->toBeNull()
        ->and(orderedQuantity($order))->toBe(2)
        ->and((int) $order->total)->toBe((int) $session->amount_total);
});
