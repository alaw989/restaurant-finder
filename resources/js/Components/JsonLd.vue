<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'

/**
 * Renders a JSON-LD `<script type="application/ld+json">` into the document
 * `<head>`.
 *
 * Why this exists (spec-038/040): the structured-data scripts were placed
 * inside Inertia's `<Head>` component, but the Inertia Vue3 `<Head>` wrapper
 * does not pass `<script>` children through to its head manager — so every
 * JSON-LD block (WebSite/Organization, ItemList, Restaurant) was silently
 * dropped from the DOM in production. Injecting imperatively here sidesteps
 * the head manager entirely and is reliable in the browser.
 *
 * Caveat: this is client-side only (no SSR yet). When SSR lands, structured
 * data should also be rendered server-side for crawlable initial HTML.
 */
const props = defineProps<{
    data: Record<string, unknown> | Record<string, unknown>[] | null
}>()

const el = ref<HTMLScriptElement | null>(null)

function sync() {
    // No-op during SSR — this component is compiled into the SSR bundle
    // (vite build --ssr) even though no SSR server runs in prod today. Guard
    // `document` so enabling SSR later won't throw "document is not defined".
    if (typeof document === 'undefined') {
        return
    }
    el.value?.remove()
    el.value = null
    if (props.data == null) {
        return
    }
    const script = document.createElement('script')
    script.type = 'application/ld+json'
    script.text = JSON.stringify(props.data)
    document.head.appendChild(script)
    el.value = script
}

watch(() => props.data, sync, { immediate: true })
onBeforeUnmount(() => el.value?.remove())
</script>

<template>
    <!-- structured data is injected into <head> imperatively -->
</template>
