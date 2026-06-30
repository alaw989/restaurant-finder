<script setup lang="ts">
/**
 * The iPop360 logo — the original brand PNG (orbit ring + "ipop360" wordmark).
 *
 * Restored from the original raster asset (spec-039 had rebuilt it as an inline
 * SVG, which flattened the smooth gradient ring into three choppy dashed arcs
 * and swapped the real wordmark for a CSS approximation). Rendered theme-aware
 * via <picture>: the canonical asset in light mode, and a variant with the
 * wordmark recolored white in dark mode — the colourful ring reads on any
 * background, so only the dark-navy wordmark needed inverting. Both assets are
 * downscaled to a retina-safe 480px tall.
 *
 * The asset is a vertical (mark-over-wordmark) lockup, so it scales as a unit
 * with height tracking 1em: set a font-size on the element (e.g.
 * class="text-2xl") to size it. (The legacy `wordmark`/`stacked` props are gone
 * — the PNG is one fixed image; the horizontal nav lockup is now this mark.)
 */
</script>

<template>
    <picture class="inline-flex shrink-0">
        <source srcset="/img/ipop360-logo-dark.png" media="(prefers-color-scheme: dark)" />
        <img
            src="/img/ipop360-logo.png"
            alt="iPop360"
            class="h-[1em] w-auto select-none"
            draggable="false"
        />
    </picture>
</template>
