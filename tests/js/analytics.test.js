// GA4 checkout step events (resources/js/utils/analytics.js).
// Run with: npm test
import { afterEach, test } from 'node:test'
import assert from 'node:assert/strict'
import { analyticsItems, fromMinor, trackCheckoutEvent, trackCheckoutStart } from '../../resources/js/utils/analytics.js'

const items = [
  { id: 1, sku: 'PUMP-1', title: 'Pump', qty: 2, price: 1299 },
  { id: 2, sku: null, title: 'Seal', qty: 1, price: 50 },
]

afterEach(() => {
  delete globalThis.window
})

test('converts minor units by the currency', () => {
  assert.equal(fromMinor(1299, 'GBP'), 12.99)
  assert.equal(fromMinor(1299, 'JPY'), 1299)
  assert.equal(fromMinor(12999, 'KWD'), 12.999)
})

test('maps checkout lines to GA4 items, falling back to the line id', () => {
  assert.deepEqual(analyticsItems(items, 'GBP'), [
    { item_id: 'PUMP-1', item_name: 'Pump', price: 12.99, quantity: 2 },
    { item_id: '2', item_name: 'Seal', price: 0.5, quantity: 1 },
  ])
})

test('pushes the event with value, extras and items when a data layer exists', () => {
  globalThis.window = { dataLayer: [] }

  const sent = trackCheckoutEvent('add_shipping_info', { items, currency: 'GBP', shipping_tier: 'Next day' })

  assert.equal(sent, true)
  assert.deepEqual(globalThis.window.dataLayer, [
    { ecommerce: null },
    {
      event: 'add_shipping_info',
      ecommerce: {
        currency: 'GBP',
        value: 26.48,
        shipping_tier: 'Next day',
        items: analyticsItems(items, 'GBP'),
      },
    },
  ])
})

test('sends nothing without a data layer (no tag manager, or no consent)', () => {
  globalThis.window = {}

  assert.equal(trackCheckoutEvent('add_payment_info', { items, currency: 'GBP', payment_type: 'card' }), false)
  assert.equal(globalThis.window.dataLayer, undefined)
})

function storage() {
  const values = new Map()

  return {
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, String(value)),
  }
}

test('sends begin_checkout once per checkout session', () => {
  globalThis.window = { dataLayer: [], sessionStorage: storage() }

  assert.equal(trackCheckoutStart('session-1', { items, currency: 'GBP' }), true)
  assert.equal(trackCheckoutStart('session-1', { items, currency: 'GBP' }), false)
  assert.equal(trackCheckoutStart('session-2', { items, currency: 'GBP' }), true)

  const events = globalThis.window.dataLayer.filter((entry) => entry.event)
  assert.deepEqual(events.map((entry) => entry.event), ['begin_checkout', 'begin_checkout'])
  assert.equal(events[0].ecommerce.value, 26.48)
})

test('does not mark the session begun when nothing could be sent', () => {
  globalThis.window = { sessionStorage: storage() }

  assert.equal(trackCheckoutStart('session-1', { items, currency: 'GBP' }), false)

  globalThis.window.dataLayer = []

  assert.equal(trackCheckoutStart('session-1', { items, currency: 'GBP' }), true)
})
