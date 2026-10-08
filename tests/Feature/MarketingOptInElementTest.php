<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lunar\Checkout\Contracts\CheckoutDriver;
use Lunar\Checkout\Contracts\ElementRegistry;
use Lunar\Checkout\Elements\MarketingOptIn;
use Lunar\Checkout\Session\ModelElementStore;
use Lunar\Core\Facades\CartSession;
use Lunar\Tests\Checkout\TestCase;
use Lunar\Tests\Checkout\Utils\CheckoutCart;

uses(TestCase::class, RefreshDatabase::class);

function marketingSession()
{
    $cart = CheckoutCart::orderable();
    CartSession::use($cart);

    app(ElementRegistry::class)->add(MarketingOptIn::class);

    return app(CheckoutDriver::class)->resolveOrCreateSession($cart);
}

it('sits in a region no layout slot renders, since the box lives inside other sections', function () {
    $element = new MarketingOptIn;

    expect($element->handle())->toBe('marketing')
        ->and($element->region())->toBe('inline')
        ->and($element->props())->toBe(['label' => 'Email me with offers and new products.']);
});

it('takes its label from config', function () {
    config(['lunar.checkout.marketing.label' => 'Send me trade offers.']);

    expect((new MarketingOptIn)->props()['label'])->toBe('Send me trade offers.');
});

it('records a tick and an untick as booleans on the session', function (mixed $posted, bool $stored) {
    $session = marketingSession();

    $this->post(route('lunar.checkout.elements.store', ['session' => $session->uuid, 'handle' => 'marketing']), [
        'opt_in' => $posted,
    ])->assertRedirect();

    expect($session->fresh()->getElementData('marketing'))->toBe(['opt_in' => $stored]);
})->with([
    'ticked' => [true, true],
    'ticked as a form string' => ['1', true],
    'unticked' => [false, false],
    'unticked as a form string' => ['0', false],
]);

it('requires an answer', function () {
    $session = marketingSession();

    $this->post(route('lunar.checkout.elements.store', ['session' => $session->uuid, 'handle' => 'marketing']), [])
        ->assertSessionHasErrors('opt_in');
});

it('starts unticked and seeds from what was captured', function () {
    $session = marketingSession();

    $fresh = (new MarketingOptIn)->setDataStore(new ModelElementStore($session));
    expect($fresh->data())->toBe([]);

    (new ModelElementStore($session))->put('marketing', ['opt_in' => true]);

    $element = (new MarketingOptIn)->setDataStore(new ModelElementStore($session->fresh()));
    expect($element->data())->toBe(['opt_in' => true]);
});
