<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lunar\Checkout\Contracts\Actions\ReconcilesCheckoutSession;
use Lunar\Checkout\States\CheckoutSession\Completed;
use Lunar\Checkout\States\CheckoutSession\Open;
use Lunar\Checkout\States\CheckoutSession\PaymentProcessing;
use Lunar\Core\Contracts\ReconcilesPaymentIntents;
use Lunar\Core\DataObjects\PaymentIntentSnapshot;
use Lunar\Core\DataObjects\PaymentRefund;
use Lunar\Core\DataObjects\PriceValue;
use Lunar\Core\Enums\PaymentIntentStatus;
use Lunar\Core\Exceptions\PaymentIntentException;
use Lunar\Core\Facades\Payments;
use Lunar\Core\Models\Cart;
use Lunar\Core\Models\Order;
use Lunar\Core\PaymentTypes\OfflinePayment;
use Lunar\Tests\Checkout\TestCase;
use Lunar\Tests\Checkout\Utils\CheckoutCart;
use Lunar\Tests\Checkout\Utils\FakeIntents;

uses(TestCase::class, RefreshDatabase::class);

/**
 * A gateway whose intent outcome the test scripts. Extends the offline type so
 * authorize()/refund() satisfy the abstract payment surface.
 */
class ReleaseTestGateway extends OfflinePayment implements ReconcilesPaymentIntents
{
    /** Null scripts a reference the gateway did not create for the cart. */
    public static ?PaymentIntentStatus $status = PaymentIntentStatus::Processing;

    public static bool $throwOnFetch = false;

    /** @var array<int, int> */
    public static array $fetchedCartIds = [];

    public function fetchIntent(Cart $cart, string $reference): ?PaymentIntentSnapshot
    {
        static::$fetchedCartIds[] = $cart->id;

        if (static::$throwOnFetch) {
            throw new PaymentIntentException('gateway unreachable');
        }

        return static::$status ? FakeIntents::snapshot($cart, static::$status) : null;
    }

    public function voidIntent(Cart $cart, string $reference): void {}

    public function refundIntent(Cart $cart, string $reference, PriceValue $amount, string $idempotencyKey): PaymentRefund
    {
        return new PaymentRefund(success: true, reference: 're_test');
    }
}

function pinnedSession(array $overrides = [])
{
    $session = CheckoutCart::session(CheckoutCart::orderable());

    $session->forceFill(array_merge([
        'status' => PaymentProcessing::$name,
        'payment_intent_ref' => 'pi_test_123',
        'payment_processing_at' => now(),
        'meta' => ['payment_method' => 'release-test'],
    ], $overrides))->save();

    return $session->refresh();
}

beforeEach(function () {
    Payments::extend('release-test', fn () => app(ReleaseTestGateway::class));
    ReleaseTestGateway::$status = PaymentIntentStatus::Processing;
    ReleaseTestGateway::$throwOnFetch = false;
    ReleaseTestGateway::$fetchedCartIds = [];
});

dataset('in-flight intent statuses', [
    'waiting on the customer' => [PaymentIntentStatus::RequiresAction],
    'gateway still deciding' => [PaymentIntentStatus::Processing],
]);

it('reconciles an in-flight intent to pending without consuming an attempt', function (PaymentIntentStatus $status) {
    ReleaseTestGateway::$status = $status;

    $session = pinnedSession();

    expect(app(ReconcilesCheckoutSession::class)->execute($session))->toBe('pending');

    $session->refresh();

    expect($session->status)->toBeInstanceOf(PaymentProcessing::class)
        ->and($session->reconciliation_attempts)->toBe(0)
        ->and($session->payment_intent_ref)->toBe('pi_test_123')
        ->and(ReleaseTestGateway::$fetchedCartIds)->toBe([(int) $session->cart_reference]);
})->with('in-flight intent statuses');

it('counts an attempt when the gateway did not create the reference for the cart', function () {
    ReleaseTestGateway::$status = null;

    $session = pinnedSession();

    expect(app(ReconcilesCheckoutSession::class)->execute($session))->toBe('retry');

    $session->refresh();

    expect($session->status)->toBeInstanceOf(PaymentProcessing::class)
        ->and($session->reconciliation_attempts)->toBe(1);
});

it('counts an attempt without asking the gateway when the cart is gone', function () {
    ReleaseTestGateway::$status = PaymentIntentStatus::Captured;

    $session = pinnedSession(['cart_reference' => '999999']);

    expect(app(ReconcilesCheckoutSession::class)->execute($session))->toBe('retry')
        ->and($session->refresh()->reconciliation_attempts)->toBe(1)
        ->and(ReleaseTestGateway::$fetchedCartIds)->toBeEmpty();
});

it('reopens a pinned session when the gateway holds no captured money', function (PaymentIntentStatus $status) {
    ReleaseTestGateway::$status = $status;

    $session = pinnedSession();

    $this->postJson(route('lunar.checkout.payment-release', $session->uuid))
        ->assertOk()
        ->assertJsonPath('released', true)
        ->assertJsonPath('outcome', 'released')
        ->assertJsonStructure(['fingerprint']);

    $session->refresh();

    // Open again for a retry, and the intent reference survives: the mounted
    // payment element is still bound to it.
    expect($session->status)->toBeInstanceOf(Open::class)
        ->and($session->payment_intent_ref)->toBe('pi_test_123');
})->with('in-flight intent statuses');

it('completes instead of releasing when the money was captured', function () {
    ReleaseTestGateway::$status = PaymentIntentStatus::Captured;

    $session = pinnedSession();

    $this->postJson(route('lunar.checkout.payment-release', $session->uuid))
        ->assertOk()
        ->assertJsonPath('outcome', 'completed')
        ->assertJsonPath('released', false);

    expect($session->refresh()->status)->toBeInstanceOf(Completed::class)
        ->and(Order::query()->count())->toBe(1);
});

it('stays frozen when the gateway outcome cannot be confirmed', function () {
    ReleaseTestGateway::$throwOnFetch = true;

    $session = pinnedSession();

    $this->postJson(route('lunar.checkout.payment-release', $session->uuid))
        ->assertOk()
        ->assertJsonPath('outcome', 'unconfirmed')
        ->assertJsonPath('released', false);

    expect($session->refresh()->status)->toBeInstanceOf(PaymentProcessing::class);
});

it('stays frozen when the gateway did not create the reference for the cart', function () {
    ReleaseTestGateway::$status = null;

    $session = pinnedSession();

    $this->postJson(route('lunar.checkout.payment-release', $session->uuid))
        ->assertOk()
        ->assertJsonPath('outcome', 'unconfirmed')
        ->assertJsonPath('released', false);

    expect($session->refresh()->status)->toBeInstanceOf(PaymentProcessing::class);
});

it('is a no-op on a session that is not pinned', function () {
    $session = CheckoutCart::session(CheckoutCart::orderable());

    $this->postJson(route('lunar.checkout.payment-release', $session->uuid))
        ->assertOk()
        ->assertJsonPath('outcome', 'not-applicable')
        ->assertJsonPath('released', false);

    expect($session->refresh()->status)->toBeInstanceOf(Open::class);
});

it('maps a frozen write to a 409 with customer copy, not a 500', function () {
    $session = pinnedSession();

    $this->postJson(route('lunar.checkout.billing-address.store', $session->uuid), [
        'first_name' => 'Ada', 'last_name' => 'Lovelace', 'line1' => '1 Test St',
        'city' => 'London', 'postcode' => 'SW1A 2AA', 'country_code' => 'GB',
    ])->assertStatus(409)
        ->assertJsonPath('reason', 'frozen')
        ->assertJsonPath('message', 'This checkout is busy finishing a payment attempt. Wait a moment and try again.');
});

it('settles a captured pinned session when the customer views it', function () {
    ReleaseTestGateway::$status = PaymentIntentStatus::Captured;

    $session = pinnedSession([
        'payment_processing_at' => now()->subSeconds(10),
        'success_url' => 'https://store.test/thanks',
    ]);

    // The customer's poll IS the webhook-less completion signal.
    $this->get(route('lunar.checkout.show', $session->uuid))
        ->assertRedirect('https://store.test/thanks');

    expect($session->refresh()->status)->toBeInstanceOf(Completed::class)
        ->and(Order::query()->count())->toBe(1);
});

it('does not settle a freshly pinned session on view', function () {
    ReleaseTestGateway::$status = PaymentIntentStatus::Captured;

    // Inside the age gate: the immediate post-confirm poll must not race the
    // webhook with a second settlement attempt.
    $session = pinnedSession(['payment_processing_at' => now()]);

    // X-Inertia: JSON page object, so testbench need not render the root
    // blade (no published Vite build there) — same as CheckoutRouteTest.
    $this->get(route('lunar.checkout.show', $session->uuid), ['X-Inertia' => 'true'])->assertOk();

    expect($session->refresh()->status)->toBeInstanceOf(PaymentProcessing::class);
});

it('reopens a failed pinned session when the customer views it', function () {
    ReleaseTestGateway::$status = PaymentIntentStatus::Failed;

    $session = pinnedSession(['payment_processing_at' => now()->subSeconds(10)]);

    $this->get(route('lunar.checkout.show', $session->uuid), ['X-Inertia' => 'true'])->assertOk();

    expect($session->refresh()->status)->toBeInstanceOf(Open::class);
});

it('processing settles a captured session and forwards to the success url', function () {
    ReleaseTestGateway::$status = PaymentIntentStatus::Captured;

    $session = pinnedSession(['success_url' => 'https://store.test/thanks']);

    $this->get(route('lunar.checkout.processing', $session->uuid))
        ->assertRedirect('https://store.test/thanks')
        // The confirmation handover: the store's success page reads this to
        // load the placed order. put(), not flash - it must survive the
        // processing page's probe fetch and a refresh of the success page.
        ->assertSessionHas('lunar.checkout.completed.uuid', $session->uuid)
        ->assertSessionHas('lunar.checkout.completed.order_reference', (string) Order::query()->firstOrFail()->id);

    expect($session->refresh()->status)->toBeInstanceOf(Completed::class)
        ->and(Order::query()->count())->toBe(1);
});

it('processing falls back to the configured store success url', function () {
    config()->set('lunar.checkout.urls.success', 'https://store.test/order-complete');

    ReleaseTestGateway::$status = PaymentIntentStatus::Captured;

    $session = pinnedSession();

    $this->get(route('lunar.checkout.processing', $session->uuid))
        ->assertRedirect('https://store.test/order-complete');
});

it('processing sends a failed payment back to the checkout to retry', function () {
    ReleaseTestGateway::$status = PaymentIntentStatus::Failed;

    $session = pinnedSession();

    $this->get(route('lunar.checkout.processing', $session->uuid))
        ->assertRedirect(route('lunar.checkout.show', $session->uuid).'?payment=failed');

    expect($session->refresh()->status)->toBeInstanceOf(Open::class);
});

it('processing renders the polling page while the outcome is in flight', function (PaymentIntentStatus $status) {
    ReleaseTestGateway::$status = $status;

    $session = pinnedSession();

    $this->get(route('lunar.checkout.processing', $session->uuid), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'Processing')
        ->assertJsonPath('props.pollUrl', route('lunar.checkout.processing', $session->uuid));

    expect($session->refresh()->status)->toBeInstanceOf(PaymentProcessing::class);
})->with('in-flight intent statuses');

it('processing shows the same merchant name as the checkout', function () {
    ReleaseTestGateway::$status = PaymentIntentStatus::Processing;
    config(['lunar.checkout.merchant' => 'Edwardes Bros', 'app.name' => 'Lunar Store']);

    $session = pinnedSession();

    $this->get(route('lunar.checkout.processing', $session->uuid), ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.merchant', 'Edwardes Bros');
});

it('processing redirects an already completed session straight to success', function () {
    $session = pinnedSession();
    $session->forceFill(['status' => Completed::$name, 'success_url' => 'https://store.test/thanks'])->save();

    $this->get(route('lunar.checkout.processing', $session->uuid))
        ->assertRedirect('https://store.test/thanks');
});
