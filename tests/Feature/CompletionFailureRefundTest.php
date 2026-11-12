<?php

use Illuminate\Database\DeadlockException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Lunar\Checkout\Contracts\Actions\ReconcilesCheckoutSession;
use Lunar\Checkout\Contracts\CheckoutDriver;
use Lunar\Checkout\Contracts\PaymentMethodRegistry;
use Lunar\Checkout\Events\CheckoutCompletionFailed;
use Lunar\Checkout\Events\OrderPlacing;
use Lunar\Checkout\Exceptions\CheckoutSessionConflictException;
use Lunar\Checkout\Models\CheckoutSession;
use Lunar\Checkout\PaymentMethods\AbstractPaymentMethod;
use Lunar\Checkout\States\CheckoutSession\Completed;
use Lunar\Checkout\States\CheckoutSession\Open;
use Lunar\Checkout\States\CheckoutSession\PaymentProcessing;
use Lunar\Core\Contracts\CreatesPaymentIntents;
use Lunar\Core\DataObjects\PaymentAuthorize;
use Lunar\Core\DataObjects\PaymentIntentDescriptor;
use Lunar\Core\DataObjects\PaymentIntentSnapshot;
use Lunar\Core\DataObjects\PaymentRefund;
use Lunar\Core\DataObjects\PriceValue;
use Lunar\Core\Enums\PaymentIntentStatus;
use Lunar\Core\Events\PaymentAttemptEvent;
use Lunar\Core\Exceptions\PaymentIntentException;
use Lunar\Core\Facades\CartSession;
use Lunar\Core\Facades\Payments;
use Lunar\Core\Models\Cart;
use Lunar\Core\Models\Order;
use Lunar\Core\PaymentTypes\OfflinePayment;
use Lunar\Tests\Checkout\TestCase;
use Lunar\Tests\Checkout\Utils\CheckoutCart;
use Lunar\Tests\Checkout\Utils\FailingOrderCreationStep;
use Lunar\Tests\Checkout\Utils\FakeIntents;

uses(TestCase::class, RefreshDatabase::class);

class CapturedIntentGateway extends OfflinePayment implements CreatesPaymentIntents
{
    /** @var array<int, array{reference: string, amount: int, currency: string}> */
    public static array $refunds = [];

    public static bool $refundFails = false;

    public static int $captured = 0;

    /** What the gateway holds as refunded, whatever the caller heard back. */
    public static int $refunded = 0;

    /** Thrown once the gateway has made the refund: the caller never hears of it. */
    public static ?Throwable $failAfterRefunding = null;

    public function createIntent(Cart $cart): PaymentIntentDescriptor
    {
        return new PaymentIntentDescriptor('pi_captured_'.$cart->id);
    }

    public function fetchIntent(Cart $cart, string $reference): ?PaymentIntentSnapshot
    {
        return FakeIntents::snapshot($cart, PaymentIntentStatus::Captured, static::$captured, refunded: static::$refunded);
    }

    public function voidIntent(Cart $cart, string $reference): void {}

    public function refundIntent(Cart $cart, string $reference, PriceValue $amount, string $idempotencyKey): PaymentRefund
    {
        if (static::$refundFails) {
            throw new PaymentIntentException('gateway refund failed');
        }

        if (static::$refunded > 0) {
            throw new PaymentIntentException('nothing left to refund');
        }

        static::$refunds[] = ['reference' => $reference, 'amount' => $amount->value, 'currency' => $amount->resolveCurrency()->code];
        static::$refunded = $amount->value;

        if (static::$failAfterRefunding !== null) {
            throw static::$failAfterRefunding;
        }

        return new PaymentRefund(success: true, reference: 'refund_'.$reference);
    }
}

class CapturedIntentMethod extends AbstractPaymentMethod
{
    public function handle(): string
    {
        return 'captured-card';
    }

    public function label(): string
    {
        return 'Card';
    }

    public function driver(): string
    {
        return 'captured-intent';
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
    CapturedIntentGateway::$refunds = [];
    CapturedIntentGateway::$refundFails = false;
    CapturedIntentGateway::$captured = 0;
    CapturedIntentGateway::$refunded = 0;
    CapturedIntentGateway::$failAfterRefunding = null;

    Payments::extend('captured-intent', fn () => app(CapturedIntentGateway::class));
    app(PaymentMethodRegistry::class)->add(CapturedIntentMethod::class);
});

function capturedSession(): CheckoutSession
{
    $cart = CheckoutCart::orderable();
    CartSession::use($cart);
    $session = CheckoutCart::session($cart);

    $session->forceFill([
        'status' => PaymentProcessing::$name,
        'payment_intent_ref' => 'pi_captured_pinned',
        'payment_processing_at' => now(),
        'cart_fingerprint' => CheckoutCart::fingerprint($session),
        'meta' => ['payment_method' => 'captured-intent', 'payment_handle' => 'captured-card'],
    ])->save();

    return $session->refresh();
}

it('refunds and reopens when the order cannot be built after the money was captured', function () {
    Exceptions::fake();
    $session = capturedSession();

    FailingOrderCreationStep::register();

    $outcome = app(ReconcilesCheckoutSession::class)->execute($session);

    expect($outcome)->toBe('refunded')
        ->and(CapturedIntentGateway::$refunds)->toBe([['reference' => 'pi_captured_pinned', 'amount' => $session->amount_total, 'currency' => 'GBP']])
        ->and($session->refresh()->status)->toBeInstanceOf(Open::class)
        ->and(Order::query()->whereNotNull('placed_at')->count())->toBe(0);

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'order pipeline down');
});

it('keeps the sweep going past a session whose completion throws', function () {
    Exceptions::fake();
    $failing = capturedSession();
    $failing->forceFill(['payment_processing_at' => now()->subHours(2)])->save();

    FailingOrderCreationStep::register();

    $this->artisan('lunar:checkout:reconcile')->assertSuccessful();

    expect($failing->refresh()->status)->toBeInstanceOf(Open::class)
        ->and(CapturedIntentGateway::$refunds)->toHaveCount(1);
});

it('never refunds a session a concurrent completion already placed', function () {
    $session = capturedSession();

    $real = app(CheckoutDriver::class);
    $proxy = Mockery::mock($real)->makePartial();
    $proxy->shouldReceive('complete')->andReturnUsing(function (CheckoutSession $s) use ($real) {
        // The other process (the webhook) wins the guarded write first.
        $real->complete($s->replicate()->forceFill(['id' => $s->id])->setRelations([]));

        throw new CheckoutSessionConflictException('frozen');
    });
    app()->instance(CheckoutDriver::class, $proxy);

    $outcome = app(ReconcilesCheckoutSession::class)->execute($session);

    expect($outcome)->toBe('completed')
        ->and(CapturedIntentGateway::$refunds)->toBeEmpty()
        ->and($session->refresh()->status)->toBeInstanceOf(Completed::class)
        ->and(Order::query()->whereNotNull('placed_at')->count())->toBe(1);
});

it('retries a deadlocked completion on a later pass instead of refunding', function () {
    Exceptions::fake();
    $session = capturedSession();

    FailingOrderCreationStep::register('SQLSTATE[40001]: Deadlock found when trying to get lock; try restarting transaction');

    $outcome = app(ReconcilesCheckoutSession::class)->execute($session);

    expect($outcome)->toBe('retry')
        ->and(CapturedIntentGateway::$refunds)->toBeEmpty()
        ->and($session->refresh()->status)->toBeInstanceOf(PaymentProcessing::class)
        ->and($session->reconciliation_attempts)->toBe(1);

    Exceptions::assertReported(DeadlockException::class);
});

it('never completes a session reconcile reopened while the async completion ran', function () {
    $session = capturedSession();
    $stale = CheckoutSession::query()->findOrFail($session->id);

    // Reconcile refunded and reopened it after this copy was read.
    $session->forceFill(['status' => Open::$name, 'payment_intent_ref' => null])->save();

    expect(fn () => app(CheckoutDriver::class)->complete($stale))
        ->toThrow(CheckoutSessionConflictException::class);

    expect($session->refresh()->status)->toBeInstanceOf(Open::class)
        ->and(Order::query()->whereNotNull('placed_at')->count())->toBe(0);
});

it('keeps the session reopened when a listener throws after the refund', function () {
    Exceptions::fake();
    $session = capturedSession();

    FailingOrderCreationStep::register();
    Event::listen(CheckoutCompletionFailed::class, fn () => throw new LogicException('listener down'));

    expect(fn () => app(ReconcilesCheckoutSession::class)->execute($session))
        ->toThrow(LogicException::class, 'listener down');

    expect(CapturedIntentGateway::$refunds)->toHaveCount(1)
        ->and($session->refresh()->status)->toBeInstanceOf(Open::class)
        ->and($session->payment_intent_ref)->toBeNull();
});

it('never refunds money whose order the gateway already placed', function () {
    Exceptions::fake();
    $session = capturedSession();
    Cart::query()->findOrFail((int) $session->cart_reference)->createOrder()->update(['placed_at' => now()]);

    Event::listen(OrderPlacing::class, fn () => throw new LogicException('placement vetoed'));

    $outcome = app(ReconcilesCheckoutSession::class)->execute($session);

    expect($outcome)->toBe('retry')
        ->and(CapturedIntentGateway::$refunds)->toBeEmpty()
        ->and($session->refresh()->status)->toBeInstanceOf(PaymentProcessing::class)
        ->and($session->reconciliation_attempts)->toBe(1);
});

it('leaves a session alone that was re-pinned against a new intent meanwhile', function () {
    Exceptions::fake();
    $session = capturedSession();

    $real = app(CheckoutDriver::class);
    $proxy = Mockery::mock($real)->makePartial();
    $proxy->shouldReceive('complete')->andReturnUsing(function (CheckoutSession $s) {
        CheckoutSession::query()->whereKey($s->id)->update(['payment_intent_ref' => 'pi_newer']);

        throw new RuntimeException('order pipeline down');
    });
    app()->instance(CheckoutDriver::class, $proxy);

    $outcome = app(ReconcilesCheckoutSession::class)->execute($session);

    expect($outcome)->toBe('not-applicable')
        ->and(CapturedIntentGateway::$refunds)->toBeEmpty()
        ->and($session->refresh()->payment_intent_ref)->toBe('pi_newer');
});

it('still refunds when the only placed order on the cart predates this payment', function () {
    $session = capturedSession();
    Cart::query()->findOrFail((int) $session->cart_reference)->createOrder()->update(['placed_at' => now()->subDay()]);
    $session->forceFill(['cart_fingerprint' => 'stale'])->save();

    $outcome = app(ReconcilesCheckoutSession::class)->execute($session->refresh());

    expect($outcome)->toBe('refunded')
        ->and(CapturedIntentGateway::$refunds)->toHaveCount(1)
        ->and($session->refresh()->status)->toBeInstanceOf(Open::class);
});

it('counts the attempt even when a listener throws on a failed refund', function () {
    Exceptions::fake();
    CapturedIntentGateway::$refundFails = true;
    $session = capturedSession();
    $session->forceFill(['cart_fingerprint' => 'stale'])->save();

    Event::listen(CheckoutCompletionFailed::class, fn () => throw new LogicException('listener down'));

    expect(fn () => app(ReconcilesCheckoutSession::class)->execute($session->refresh()))
        ->toThrow(LogicException::class, 'listener down');

    expect($session->refresh()->status)->toBeInstanceOf(PaymentProcessing::class)
        ->and($session->reconciliation_attempts)->toBe(1);
});

it('refunds in the currency the session was pinned in after the cart changed currency', function () {
    $session = capturedSession();
    CheckoutCart::changeCurrency(Cart::query()->findOrFail((int) $session->cart_reference), 'EUR');

    $outcome = app(ReconcilesCheckoutSession::class)->execute($session);

    expect($outcome)->toBe('refunded')
        ->and(CapturedIntentGateway::$refunds)->toBe([['reference' => 'pi_captured_pinned', 'amount' => $session->amount_total, 'currency' => 'GBP']])
        ->and($session->refresh()->status)->toBeInstanceOf(Open::class);
});

it('refunds in the currency the session was pinned in when the operator forces the refund', function () {
    $session = capturedSession();
    CheckoutCart::changeCurrency(Cart::query()->findOrFail((int) $session->cart_reference), 'EUR');

    $outcome = app(ReconcilesCheckoutSession::class)->execute($session, 'refund');

    expect($outcome)->toBe('refunded')
        ->and(CapturedIntentGateway::$refunds)->toBe([['reference' => 'pi_captured_pinned', 'amount' => $session->amount_total, 'currency' => 'GBP']])
        ->and($session->refresh()->status)->toBeInstanceOf(Open::class);
});

it('refuses to refund in another currency when the store no longer has the pinned one', function () {
    Event::fake([CheckoutCompletionFailed::class]);
    $session = capturedSession();
    $session->forceFill(['currency_code' => 'USD', 'cart_fingerprint' => 'stale'])->save();

    $outcome = app(ReconcilesCheckoutSession::class)->execute($session->refresh());

    expect($outcome)->toBe('retry')
        ->and(CapturedIntentGateway::$refunds)->toBeEmpty()
        ->and($session->refresh()->status)->toBeInstanceOf(PaymentProcessing::class)
        ->and($session->reconciliation_attempts)->toBe(1);

    Event::assertDispatched(CheckoutCompletionFailed::class, fn (CheckoutCompletionFailed $event): bool => $event->reason === 'refund-failed');

    expect(fn () => app(ReconcilesCheckoutSession::class)->execute($session, 'refund'))
        ->toThrow(PaymentIntentException::class, 'USD');

    expect(CapturedIntentGateway::$refunds)->toBeEmpty();
});

it('lets the operator refund a session whose cart was pruned', function () {
    $session = capturedSession();
    Cart::query()->findOrFail((int) $session->cart_reference)->delete();

    $outcome = app(ReconcilesCheckoutSession::class)->execute($session, 'refund');

    expect($outcome)->toBe('refunded')
        ->and(CapturedIntentGateway::$refunds)->toBe([['reference' => 'pi_captured_pinned', 'amount' => $session->amount_total, 'currency' => 'GBP']])
        ->and($session->refresh()->status)->toBeInstanceOf(Open::class);
});

it('refunds a captured payment whose cart was pruned before it could complete', function () {
    Exceptions::fake();
    $session = capturedSession();
    Cart::query()->findOrFail((int) $session->cart_reference)->delete();

    $outcome = app(ReconcilesCheckoutSession::class)->execute($session);

    expect($outcome)->toBe('refunded')
        ->and(CapturedIntentGateway::$refunds)->toHaveCount(1)
        ->and($session->refresh()->status)->toBeInstanceOf(Open::class)
        ->and(Order::query()->whereNotNull('placed_at')->count())->toBe(0);
});

it('never places an order on money an earlier pass refunded before it crashed', function () {
    Exceptions::fake();
    Event::fake([CheckoutCompletionFailed::class]);
    $session = capturedSession();
    CapturedIntentGateway::$captured = $session->amount_total;
    CapturedIntentGateway::$failAfterRefunding = new RuntimeException('connection lost after the refund landed');

    $placementVetoed = true;
    Event::listen(OrderPlacing::class, function () use (&$placementVetoed) {
        if ($placementVetoed) {
            throw new LogicException('placement vetoed');
        }
    });

    expect(fn () => app(ReconcilesCheckoutSession::class)->execute($session))
        ->toThrow(RuntimeException::class, 'connection lost after the refund landed');

    expect($session->refresh()->status)->toBeInstanceOf(PaymentProcessing::class);

    $placementVetoed = false;

    $outcome = app(ReconcilesCheckoutSession::class)->execute($session);

    expect($outcome)->toBe('refunded')
        ->and($session->refresh()->status)->toBeInstanceOf(Open::class)
        ->and($session->payment_intent_ref)->toBeNull()
        ->and(Order::query()->whereNotNull('placed_at')->count())->toBe(0)
        ->and(CapturedIntentGateway::$refunds)->toHaveCount(1);

    Event::assertDispatched(
        CheckoutCompletionFailed::class,
        fn (CheckoutCompletionFailed $event): bool => $event->reason === 'refunded' && $event->refundReference === null,
    );
});

it('reopens instead of retrying a refund whose response was lost', function () {
    Event::fake([CheckoutCompletionFailed::class]);
    $session = capturedSession();
    $session->forceFill(['cart_fingerprint' => 'stale'])->save();
    CapturedIntentGateway::$captured = $session->amount_total;
    CapturedIntentGateway::$failAfterRefunding = new PaymentIntentException('refund response lost');

    expect(app(ReconcilesCheckoutSession::class)->execute($session->refresh()))->toBe('retry');

    $outcome = app(ReconcilesCheckoutSession::class)->execute($session->refresh());

    expect($outcome)->toBe('refunded')
        ->and($session->refresh()->status)->toBeInstanceOf(Open::class)
        ->and($session->payment_intent_ref)->toBeNull()
        ->and(CapturedIntentGateway::$refunds)->toHaveCount(1);

    $reasons = Event::dispatched(CheckoutCompletionFailed::class)
        ->map(fn (array $arguments): string => $arguments[0]->reason)
        ->countBy();

    expect($reasons->get('refund-failed'))->toBe(1)
        ->and($reasons->get('refunded'))->toBe(1);

    Event::assertDispatched(
        CheckoutCompletionFailed::class,
        fn (CheckoutCompletionFailed $event): bool => $event->reason === 'refunded' && $event->refundReference === null,
    );
});

it('never completes a refunded payment when the customer releases the session', function () {
    Event::fake([CheckoutCompletionFailed::class]);
    $session = capturedSession();
    CapturedIntentGateway::$captured = $session->amount_total;
    CapturedIntentGateway::$refunded = $session->amount_total;

    $outcome = app(ReconcilesCheckoutSession::class)->release($session);

    expect($outcome)->toBe('refunded')
        ->and($session->refresh()->status)->toBeInstanceOf(Open::class)
        ->and(Order::query()->whereNotNull('placed_at')->count())->toBe(0)
        ->and(CapturedIntentGateway::$refunds)->toBeEmpty();
});

it('leaves a partly refunded payment pinned for the operator', function () {
    Event::fake([CheckoutCompletionFailed::class]);
    $session = capturedSession();
    CapturedIntentGateway::$captured = $session->amount_total;
    CapturedIntentGateway::$refunded = 100;

    $outcome = app(ReconcilesCheckoutSession::class)->execute($session);

    expect($outcome)->toBe('retry')
        ->and($session->refresh()->status)->toBeInstanceOf(PaymentProcessing::class)
        ->and($session->reconciliation_attempts)->toBe(1)
        ->and(Order::query()->whereNotNull('placed_at')->count())->toBe(0)
        ->and(CapturedIntentGateway::$refunds)->toBeEmpty();

    Event::assertNotDispatched(CheckoutCompletionFailed::class);
});

it('never refunds an order the gateway placed before its success pinned the session', function () {
    Exceptions::fake();
    $session = capturedSession();
    $session->forceFill(['status' => Open::$name, 'payment_processing_at' => null])->save();

    $order = Cart::query()->findOrFail((int) $session->cart_reference)->createOrder();
    $order->update(['placed_at' => now()]);

    // The gateway announces the order after placing it, so the pin that
    // announcement causes is later than placed_at.
    $this->travel(5)->seconds();

    Event::listen(OrderPlacing::class, fn () => throw new LogicException('placement vetoed'));

    expect(fn () => PaymentAttemptEvent::dispatch(new PaymentAuthorize(success: true, orderId: $order->id, paymentType: 'captured-intent')))
        ->toThrow(LogicException::class, 'placement vetoed');

    expect($session->refresh()->status)->toBeInstanceOf(PaymentProcessing::class)
        ->and($session->payment_processing_at->gt($order->refresh()->placed_at))->toBeTrue();

    $outcome = app(ReconcilesCheckoutSession::class)->execute($session);

    expect($outcome)->toBe('retry')
        ->and(CapturedIntentGateway::$refunds)->toBeEmpty()
        ->and($session->refresh()->status)->toBeInstanceOf(PaymentProcessing::class)
        ->and($session->reconciliation_attempts)->toBe(1);
});

it('still refunds a later payment on a session whose earlier payment the gateway placed', function () {
    $session = capturedSession();

    $earlier = Cart::query()->findOrFail((int) $session->cart_reference)->createOrder();
    $earlier->update(['placed_at' => now()->subDay()]);

    $session->forceFill([
        'payment_intent_ref' => 'pi_captured_later',
        'cart_fingerprint' => 'stale',
        'meta' => array_merge((array) $session->meta, [
            'gateway_placed_order' => ['order' => $earlier->id, 'intent' => 'pi_captured_earlier'],
        ]),
    ])->save();

    $outcome = app(ReconcilesCheckoutSession::class)->execute($session->refresh());

    expect($outcome)->toBe('refunded')
        ->and(CapturedIntentGateway::$refunds)->toHaveCount(1)
        ->and($session->refresh()->status)->toBeInstanceOf(Open::class);
});
