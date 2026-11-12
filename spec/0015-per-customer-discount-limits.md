# 0015: Per-customer discount limits

- Status: draft
- Author: Glenn Jacobs
- Created: 2026-11-11
- Depends on: [[0007-discount-element]] (the coupon verbs), [[0010-cart-session-reconciliation]] §E
  (the pay boundary)
- Depends on core: `discount-coupons` (lunarphp/lunar#2848) and `guest-discount-usage-limits`
  (lunarphp/lunar#2845). Neither has landed; this spec tracks what checkout needs once they do.
- Assumes the summary's coupon field is wired to the driver's coupon verbs ([[0007-discount-element]]);
  today `useCheckout.js` checks codes against a client-side placeholder list.

## Problem

Core is changing how discount usage works:

- **Usage is counted when the order is placed** (`discount-coupons`), in `discount_redemptions`,
  not when the draft order is created. The pay gate's draft no longer spends a use, so a declined
  card no longer burns a single-use code.
- **`max_uses_per_user` applies to guests** (`guest-discount-usage-limits`). Core identifies the
  customer by the signed-in user, then the billing address `contact_email`, then the shipping
  address `contact_email`, and counts their redemptions by user or email. A discount whose limit
  the customer has reached drops off the cart at calculation.
- **`DiscountManager::validateCoupon()` takes the cart**, so it can check the limit for that
  cart's customer instead of `auth()->user()`.

Checkout already does the hard part: the contact email reaches every cart address (`storeContact()`,
`withContactEmail()`), and the pay gate refuses a cart with no contact (`assertContactKnown`). Three
things are missing:

1. **`applyCoupon()` validates without the cart.** `$this->discounts->validateCoupon($code)`
   checks the limit against `auth()->user()`, so a guest's email is ignored.
2. **A coupon that stops applying does so silently.** A guest enters a code on the summary, then
   gives an email that has already used it. Core drops the discount at the next calculation, the
   total goes up, and nothing says why.
3. **Every rejection reads "The coupon code is invalid."** A code the customer has already used is
   not invalid, and telling them so sends them looking for a typo.

## Proposal

### A. Validate against the cart

`LunarCheckoutDriver::applyCoupon()` calls `$this->discounts->validateCoupon($code, $cart)`.

### B. Say why a code was refused

When `validateCoupon()` fails, the driver checks whether the cause is the per-customer limit:
the code resolves (`$cart->coupon`, from `discount-coupons`), its discount sets
`max_uses_per_user`, and `CountsCustomerRedemptions` has reached it. That case throws
`lunar::checkout.elements.discount.already_used`; every other failure keeps
`lunar::checkout.elements.discount.invalid`. The validator stays a yes/no answer in core; only
checkout needs the reason.

### C. Re-check the coupon when the customer's identity changes

After `storeContact()`, `storeBillingAddress()` and `storeShippingAddress()`, and after
`associateCustomer()`, the driver re-validates an applied coupon against the cart. If it no longer
passes:

- the coupon is removed (`coupon_code` cleared, the cart recalculated), so the summary never shows
  a code as applied while no discount applies;
- `CouponRemoved` is dispatched with a `reason` of `already_used`;
- the response carries a one-off `couponNotice` (the code and the `already_used` message), which
  the order summary shows where the applied coupon was.

The customer can enter the code again if they change the email.

### D. The pay gate needs no new reason

Core adds `ValidateCustomerForDiscounts` to the order-create validators: a per-customer-limited
discount needs a user or an email. `assertContactKnown` runs first and already requires one, and
the contact email is on the cart's addresses by then, so this validator cannot fail at the gate.
An over-limit discount has already dropped off the total before the customer confirms the
fingerprint.

### E. Races need nothing here

Two orders from the same customer at the same moment can both pass. Core records both at
placement and dispatches `DiscountLimitExceeded`; the orders stand, matching the refund
invariant's rule that a paid customer always gets an order.

## Alternatives considered

- **Keep the coupon on the cart and flag it.** The code would stay in the summary with no
  discount against it, and a later change of email would quietly bring the discount back. Removing
  it and saying why is clearer.
- **Return a reason from core's `CouponValidator`.** It would change a contract every storefront
  implements, to serve one caller. Checkout can work the reason out from core's public actions.
- **A new pay-gate reason (`discount_limit_reached`).** Nothing reaches it: the discount drops off
  at calculation, and the gate already requires a contact.
- **Collect the email before allowing a coupon.** The summary's coupon field is available from the
  start, and most customers never hit a limit; making everyone give an email first to serve the
  few is the wrong trade.

## Migration impact

- `LunarCheckoutDriver`: `applyCoupon()` passes the cart and distinguishes `already_used`; the
  identity-changing store verbs re-check the coupon.
- `CouponRemoved` gains a nullable `reason`.
- Controller and `useCheckout.js`: `couponNotice` in the store responses and the order summary.
- 16-locale key: `lunar::checkout.elements.discount.already_used`.
- No schema change.

## Acceptance checks

- A guest applying a code limited to one use per customer, before giving an email, gets the
  discount.
- The same guest then giving an email that has redeemed the code loses the discount, the coupon
  is cleared, `CouponRemoved` carries `already_used`, and the response carries `couponNotice`.
- Applying that code after giving the email is refused with `already_used`, not `invalid`.
- A signed-in customer who has used the code is refused with `already_used`.
- An unknown or expired code is still refused with `invalid`.
- A collect (pickup) cart with a per-customer-limited discount passes the pay gate: core resolves
  the customer's email from the cart.

## Open questions

- Should the order summary show `couponNotice` inline where the coupon was, or as a toast? (Owner:
  design.)

## References

- [[0007-discount-element]], [[0010-cart-session-reconciliation]] §E.
- lunarphp/lunar#2848 (`discount-coupons`), lunarphp/lunar#2845 (`guest-discount-usage-limits`).
- `src/Drivers/LunarCheckoutDriver.php` (`applyCoupon`, `storeContact`, `assertContactKnown`),
  `resources/js/composables/useCheckout.js` (`applyDiscount`).
