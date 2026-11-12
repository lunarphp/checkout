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
use Lunar\Core\DataObjects\PaymentIntentDescriptor;
use Lunar\Core\DataObjects\PaymentIntentSnapshot;
use Lunar\Core\DataObjects\PaymentRefund;
use Lunar\Core\DataObjects\PriceValue;
use Lunar\Core\Enums\PaymentIntentStatus;
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
    /** @var array<int, array{reference: string, amount: int}> */
    public static array $refunds = [];

    public static bool $refundFails = false;

    public function createIntent(Cart $cart): PaymentIntentDescriptor
    {
        return new PaymentIntentDescriptor('pi_captured_'.$cart->id);
    }

    public function fetchIntent(Cart $cart, string $reference): ?PaymentIntentSnapshot
    {
        return FakeIntents::snapshot($cart, PaymentIntentStatus::Captured);
    }

    public function voidIntent(Cart $cart, string $reference): void {}

    public function refundIntent(Cart $cart, string $reference, PriceValue $amount, string $idempotencyKey): PaymentRefund
    {
        if (static::$refundFails) {
            throw new PaymentIntentException('gateway refund failed');
        }

        static::$refunds[] = ['reference' => $reference, 'amount' => $amount->value];

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
        ->and(CapturedIntentGateway::$refunds)->toBe([['reference' => 'pi_captured_pinned', 'amount' => $session->amount_total]])
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
