<script setup>
import { computed } from 'vue'

/**
 * The checkout footer's policy links: only the pages the store has set
 * (config urls.legal), in a fixed order.
 */
const props = defineProps({
  links: { type: Object, default: () => ({}) },
})

const PAGES = [
  ['refunds', 'Refund policy'],
  ['shipping', 'Shipping'],
  ['privacy', 'Privacy policy'],
  ['terms', 'Terms of service'],
  ['contact', 'Contact'],
]

const shown = computed(() => PAGES.filter(([key]) => props.links?.[key]).map(([key, label]) => ({ key, label, href: props.links[key] })))
</script>

<template>
  <div v-if="shown.length" class="foot">
    <a v-for="link in shown" :key="link.key" :href="link.href" target="_blank" rel="noopener">{{ link.label }}</a>
  </div>
</template>
