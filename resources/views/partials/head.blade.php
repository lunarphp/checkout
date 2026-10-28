{{--
    The host's slot in the checkout's <head>, rendered last. Empty here; a
    store fills it by publishing its own copy to
    resources/views/vendor/lunar-checkout/partials/head.blade.php, for a tag
    manager, a consent script or verification meta tags. $page (the Inertia
    page) is in scope.

    The checkout pushes GA4 step events (add_shipping_info, add_payment_info)
    to window.dataLayer when one exists, so a store that wants them creates
    it here, and only once the shopper has consented.
--}}
