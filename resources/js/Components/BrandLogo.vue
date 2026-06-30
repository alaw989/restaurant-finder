<script setup lang="ts">
/**
 * The iPop360 logo: the brand orbit-ring (from the original artwork) over an
 * "ipop360" wordmark.
 *
 * The ring is the real raster mark (spec-039's inline-SVG rebuild had flattened
 * it into choppy dashed arcs); the wordmark is rendered text so it stays
 * prominent at every size and adapts to the theme via `text-foreground` — no
 * separate dark-mode asset is needed (the colourful ring reads on any
 * background). `:wordmark="false"` renders the mark alone.
 *
 * Scales as a unit via the inherited font-size: the ring's height tracks 1em and
 * the wordmark ~0.38em, so set a font-size on the element (e.g. class="text-2xl")
 * to size the whole lockup.
 */
withDefaults(defineProps<{ wordmark?: boolean }>(), {
    wordmark: true,
})
</script>

<template>
    <span class="inline-flex flex-col items-center leading-none">
        <img
            src="/img/ipop360-mark.png"
            alt="iPop360"
            class="h-[1em] w-auto select-none"
            draggable="false"
        />
        <span
            v-if="wordmark"
            class="mt-[0.1em] font-semibold tracking-tight text-foreground"
            style="font-size: 0.38em"
        >iPop360</span>
    </span>
</template>
