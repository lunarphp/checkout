<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Lunar\Checkout\DataObjects\CheckoutTheme;
use Lunar\Tests\Checkout\TestCase;
use Lunar\Tests\Checkout\Utils\CheckoutCart;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Spec 0008 §D. The checkout is its own document, so the store's tab icon and
 * name have to be handed to it through the theme.
 */
it('renders the theme favicon and the merchant name in the root view head', function () {
    app()->bind(CheckoutTheme::class, fn (): CheckoutTheme => CheckoutTheme::tender()->with(favicon: '/favicon.svg'));
    config()->set('lunar.checkout.merchant', 'Edwardes Bros');

    $cart = CheckoutCart::orderable();
    $session = CheckoutCart::session($cart);

    $this->withoutVite()->get(route('lunar.checkout.show', $session->uuid))
        ->assertOk()
        ->assertSee('<link rel="icon" href="/favicon.svg">', false)
        ->assertSee('<title>Checkout · Edwardes Bros</title>', false);
});

it('renders no icon link when the theme sets none', function () {
    $cart = CheckoutCart::orderable();
    $session = CheckoutCart::session($cart);

    $this->withoutVite()->get(route('lunar.checkout.show', $session->uuid))
        ->assertOk()
        ->assertDontSee('rel="icon"', false);
});

it('refuses an unsafe favicon url', function () {
    expect(fn () => CheckoutTheme::tender()->with(favicon: 'javascript:alert(1)')->favicon())
        ->toThrow(InvalidArgumentException::class);
});

it('emits the pinnable accent tints as tokens', function () {
    $tokens = CheckoutTheme::tender()->with(
        accent: '#2d8e42',
        accentSoft: '#ecf7ee',
        accentSoftBorder: '#d3ecd9',
        accentRing: 'rgba(45, 142, 66, 0.16)',
    )->tokens();

    expect($tokens)->toBe([
        '--accent' => '#2d8e42',
        '--accent-soft' => '#ecf7ee',
        '--accent-soft-border' => '#d3ecd9',
        '--accent-ring' => 'rgba(45, 142, 66, 0.16)',
    ]);
});

/**
 * The host's head hook: a store overriding lunar-checkout::partials.head
 * (a tag manager, a consent script) gets it into the checkout document.
 */
it('renders the host head partial when the store overrides it', function () {
    $views = sys_get_temp_dir().'/lunar-checkout-head-'.uniqid();
    mkdir($views.'/partials', recursive: true);
    file_put_contents($views.'/partials/head.blade.php', '<script data-test="host-head">window.hostHead = true</script>');

    View::prependNamespace('lunar-checkout', $views);

    $cart = CheckoutCart::orderable();
    $session = CheckoutCart::session($cart);

    $this->withoutVite()->get(route('lunar.checkout.show', $session->uuid))
        ->assertOk()
        ->assertSee('<script data-test="host-head">window.hostHead = true</script>', false);
});

it('adds nothing to the head when the store leaves the hook alone', function () {
    $cart = CheckoutCart::orderable();
    $session = CheckoutCart::session($cart);

    $this->withoutVite()->get(route('lunar.checkout.show', $session->uuid))
        ->assertOk()
        ->assertDontSee('dataLayer', false)
        ->assertDontSee('googletagmanager', false);
});
