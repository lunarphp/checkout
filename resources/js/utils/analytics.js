// GA4 checkout events (begin_checkout, add_shipping_info, add_payment_info)
// for a store running Google Tag Manager.
//
// The checkout never loads a tag itself. A host that wants measurement puts
// its container (and its consent handling) in the head hook,
// resources/views/vendor/lunar-checkout/partials/head.blade.php, and creates
// `window.dataLayer` only when the shopper has consented. No data layer, no
// events: a store without Tag Manager, or a shopper who said no, sends nothing.

/** Decimal places of a currency's minor unit: 2 for GBP, 0 for JPY. */
function currencyDecimals(currency) {
  try {
    return new Intl.NumberFormat('en', { style: 'currency', currency }).resolvedOptions().maximumFractionDigits ?? 2
  } catch {
    return 2
  }
}

/** Minor units (1299) to GA4's major units (12.99). */
export function fromMinor(minor, currency) {
  return Math.round(minor || 0) / 10 ** currencyDecimals(currency)
}

/** Checkout lines as GA4 items, priced per unit. */
export function analyticsItems(items, currency) {
  return items.map((item) => ({
    item_id: item.sku ?? String(item.id),
    item_name: item.title ?? '',
    price: fromMinor(item.price, currency),
    quantity: item.qty,
  }))
}

function itemsValue(items) {
  const total = items.reduce((sum, item) => sum + item.price * item.quantity, 0)

  // Floating point noise only (0.1 * 3), not real precision.
  return Math.round(total * 1e6) / 1e6
}

/**
 * Push a GA4 e-commerce event, if the host has a data layer. Returns whether
 * it was sent.
 */
export function trackCheckoutEvent(event, { items, currency, ...extra }) {
  if (typeof window === 'undefined' || !Array.isArray(window.dataLayer)) return false

  const lines = analyticsItems(items, currency)

  // Clear the previous event's ecommerce object, as Google recommends, so
  // fields don't leak from one event into the next.
  window.dataLayer.push({ ecommerce: null })
  window.dataLayer.push({
    event,
    ecommerce: { currency, value: itemsValue(lines), ...extra, items: lines },
  })

  return true
}

/** Checkout sessions already announced in this tab, so a reload isn't a second start. */
const BEGUN_KEY = 'lunar_checkout_begun'

/**
 * begin_checkout, once per checkout session. Sent from the checkout itself
 * rather than the store's "Checkout" button: the button's request ends in a
 * full page visit, so the store can't tell it succeeded, and every way in
 * (basket, Buy Now, a resumed link) lands here.
 */
export function trackCheckoutStart(uuid, payload) {
  if (!uuid) return false

  let begun = []

  try {
    begun = JSON.parse(window.sessionStorage.getItem(BEGUN_KEY) ?? '[]')
  } catch {
    // Storage blocked or corrupt: send it rather than lose the step.
  }

  if (begun.includes(uuid)) return false

  if (!trackCheckoutEvent('begin_checkout', payload)) return false

  try {
    window.sessionStorage.setItem(BEGUN_KEY, JSON.stringify([...begun, uuid].slice(-20)))
  } catch {
    // As above.
  }

  return true
}
