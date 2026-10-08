<script setup>
/**
 * A link to one of the store's policy pages (config urls.legal), or plain
 * text when the store hasn't set that page, so the checkout never links to
 * nothing.
 */
defineProps({
  href: { type: String, default: null },
})
</script>

<template>
  <a v-if="href" :href="href" target="_blank" rel="noopener"><slot /></a>
  <span v-else><slot /></span>
</template>
