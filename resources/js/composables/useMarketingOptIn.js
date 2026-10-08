import { computed, onBeforeUnmount, ref } from 'vue'
import { useCheckout } from './useCheckout.js'

// The `marketing` element's tick, shared by the contact step and the express
// confirm page. `available` is false when the host has not registered the
// element, and both callers then render no box at all. Each change is saved
// at once and registered as a pending write, so ticking and clicking Pay
// straight away still lands the choice before the order is placed.
//
// A page that creates the store itself (ExpressConfirm) passes it in, since
// a component cannot inject what it provides.
export function useMarketingOptIn(store = useCheckout()) {
  const { state, storeElement, registerPendingWrite } = store

  const element = computed(() => state.elements.find((el) => el.handle === 'marketing') ?? null)
  const available = computed(() => Boolean(element.value?.storeUrl))
  const label = computed(() => element.value?.props?.label ?? 'Email me with offers and new products.')

  const optIn = ref(Boolean(element.value?.data?.opt_in))
  let inFlight = null

  function setOptIn(value) {
    optIn.value = Boolean(value)

    if (!available.value) return Promise.resolve()

    inFlight = storeElement(element.value, { opt_in: optIn.value }).finally(() => {
      inFlight = null
    })

    return inFlight
  }

  const unregister = registerPendingWrite(() => inFlight ?? Promise.resolve())
  onBeforeUnmount(unregister)

  return { available, label, optIn, setOptIn }
}
