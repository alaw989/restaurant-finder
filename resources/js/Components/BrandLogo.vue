<script setup lang="ts">
/**
 * The iPop360 brand mark + wordmark, as an inline vector.
 *
 * - Mark: three interlocking arc-circles (brand blue/orange/purple) — the "360"
 *   orbit motif, matching `public/favicon.svg`. Fixed brand colours → legible on
 *   both light and dark backgrounds.
 * - Wordmark: lowercase "ipop360" in the app font (matches the source logo
 *   asset). `text-foreground` so it adapts to the theme.
 *
 * Scales as a unit via the inherited font-size: set a font-size on the element
 * (e.g. `class="text-2xl"`) and both the mark (`h-[1em]`) and the wordmark scale
 * together. `stacked` renders the mark over the wordmark (the hero lockup);
 * default is the horizontal mark+wordmark used in the nav/compact bar.
 * `:wordmark="false"` for mark-only.
 */
withDefaults(defineProps<{ wordmark?: boolean; stacked?: boolean }>(), {
    wordmark: true,
    stacked: false,
})
</script>

<template>
    <span
        :class="[
            'inline-flex leading-none',
            stacked ? 'flex-col items-center gap-[0.14em]' : 'items-center gap-[0.3em]',
        ]"
    >
        <svg
            viewBox="0 0 44 44"
            class="h-[1em] w-auto"
            :role="wordmark ? undefined : 'img'"
            :aria-label="wordmark ? undefined : 'iPop360'"
            :aria-hidden="wordmark ? 'true' : undefined"
            fill="none"
            stroke-width="6"
            stroke-linecap="round"
        >
            <circle cx="22" cy="22" r="16" stroke="#2a7fff" stroke-dasharray="66 34" transform="rotate(-60 22 22)" />
            <circle cx="22" cy="22" r="16" stroke="#ff6b35" stroke-dasharray="66 34" transform="rotate(60 22 22)" />
            <circle cx="22" cy="22" r="16" stroke="#8a2be2" stroke-dasharray="66 34" transform="rotate(180 22 22)" />
        </svg>
        <span
            v-if="wordmark"
            class="font-semibold tracking-tight text-foreground"
            :style="{ fontSize: stacked ? '0.42em' : '0.82em' }"
        >ipop360</span>
    </span>
</template>
