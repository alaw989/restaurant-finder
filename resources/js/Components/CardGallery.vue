<script setup lang="ts">
import { computed, ref, onMounted, onUnmounted } from 'vue';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { useCardGallery } from '@/composables/useCardGallery';

const props = withDefaults(
    defineProps<{
        photos: string[];
        gradient: string;
        alt: string;
        aspect?: '4/3' | '3/2';
        /** Enable the hover/swipe gallery controls (off on the detail hero). */
        multi?: boolean;
        /** Clip class for the photo frame corners. */
        roundedClass?: string;
        /** Whether to load the image eagerly (for LCP hero). */
        eager?: boolean;
    }>(),
    { aspect: '4/3', multi: true, roundedClass: 'rounded-t-2xl', eager: false },
);

const { activeIndex, onMove, onEnter, onLeave, prev, next } = useCardGallery(() => props.photos);

const galleryActive = computed(() => props.multi && props.photos.length > 1);

const aspectClass = computed(() =>
    props.aspect === '3/2' ? 'aspect-[3/2]' : 'aspect-[4/3]',
);

// Image dimensions for CLS prevention (aspect-ratio-based)
// 4:3 = 400x300, 3:2 = 400x267 at a 400px reference
const imageWidth = computed(() => 400);
const imageHeight = computed(() => props.aspect === '3/2' ? 267 : 300);

// Sizes attribute for responsive images (reflects grid layout)
const imageSizes = computed(() => {
    // Card column widths in the responsive grid
    return '(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 33vw';
});

// Detect if device has hover capability (for gating touch controls)
const hasHover = ref(true);

function updateHoverCapability() {
    hasHover.value = window.matchMedia('(hover: hover)').matches;
}

// Lazy-mount the non-hero photo stack: render only the hero until the user shows
// intent (hover/focus) or the card nears the viewport, then mount the rest. This
// cuts up to 5 <img> elements (and their compositor layers) per offscreen card.
// The network fetch was already lazy; now the DOM/layers are too.
const expanded = ref(false);
const galleryRoot = ref<HTMLElement | null>(null);
let expandObserver: IntersectionObserver | null = null;

function expandNow() {
    if (expanded.value) return;
    expanded.value = true;
    expandObserver?.disconnect();
    expandObserver = null;
}

function handleEnter() {
    if (!galleryActive.value) return;
    expandNow();
    onEnter();
}

onMounted(() => {
    updateHoverCapability();
    window.matchMedia('(hover: hover)').addEventListener('change', updateHoverCapability);

    // Only multi-photo cards have extra images worth mounting ahead of time.
    if (galleryActive.value && galleryRoot.value && 'IntersectionObserver' in window) {
        expandObserver = new IntersectionObserver(
            (entries) => {
                for (const e of entries) {
                    if (e.isIntersecting) expandNow();
                }
            },
            { rootMargin: '200px 0px' },
        );
        expandObserver.observe(galleryRoot.value);
    }
});

onUnmounted(() => {
    window.matchMedia('(hover: hover)').removeEventListener('change', updateHoverCapability);
    expandObserver?.disconnect();
});
</script>

<template>
    <div
        ref="galleryRoot"
        class="relative w-full overflow-hidden"
        :class="[aspectClass, roundedClass]"
        @mousemove="galleryActive && expanded && onMove($event)"
        @mouseenter="handleEnter"
        @mouseleave="galleryActive && onLeave()"
        @focusin="galleryActive && expandNow()"
        @pointerdown="galleryActive && expandNow()"
    >
        <!-- cuisine gradient backdrop (perceived-perf + graceful no-photo) -->
        <div class="absolute inset-0" :style="{ background: gradient }" />

        <!-- Hero image: always rendered (LCP-relevant). Crossfades via opacity
             over the gradient — no backdrop-blur veil needed. -->
        <img
            v-if="photos[0]"
            :src="photos[0]"
            :alt="alt"
            :width="imageWidth"
            :height="imageHeight"
            :sizes="imageSizes"
            :loading="eager ? 'eager' : 'lazy'"
            :fetchpriority="eager ? 'high' : 'auto'"
            decoding="async"
            class="absolute inset-0 h-full w-full object-cover transition-opacity duration-300 ease-out"
            :class="activeIndex === 0 ? 'opacity-100' : 'opacity-0'"
        />

        <!-- Non-hero images: mounted only after `expanded` (hover/focus/near-viewport). -->
        <img
            v-for="(src, i) in (expanded ? photos.slice(1) : [])"
            :key="src + '-' + (i + 1)"
            :src="src"
            :alt="''"
            :width="imageWidth"
            :height="imageHeight"
            :sizes="imageSizes"
            loading="lazy"
            decoding="async"
            class="absolute inset-0 h-full w-full object-cover transition-opacity duration-300 ease-out"
            :class="activeIndex === i + 1 ? 'opacity-100' : 'opacity-0'"
        />

        <!-- bottom readability scrim -->
        <div
            class="pointer-events-none absolute inset-x-0 bottom-0 h-20 bg-gradient-to-t from-black/55 to-transparent"
        />

        <!-- card-specific overlays (rank, award, score, heart) -->
        <slot name="overlays" />

        <!-- gallery controls (desktop hover; tap-cycle on touch) -->
        <template v-if="galleryActive">
            <!-- Left tap zone (touch navigation) -->
            <button
                type="button"
                class="absolute inset-y-0 left-0 w-1/2 cursor-pointer opacity-0 hover:opacity-0 focus-visible:opacity-0"
                aria-hidden="true"
                tabindex="-1"
                @click.prevent="prev"
            />
            <!-- Right tap zone (touch navigation) -->
            <button
                type="button"
                class="absolute inset-y-0 right-0 w-1/2 cursor-pointer opacity-0 hover:opacity-0 focus-visible:opacity-0"
                aria-hidden="true"
                tabindex="-1"
                @click.prevent="next"
            />
            <!-- Desktop chevrons (hover-only) -->
            <button
                type="button"
                class="absolute left-2 top-1/2 z-20 flex min-h-[44px] min-w-[44px] -translate-y-1/2 items-center justify-center rounded-full bg-white/85 text-foreground opacity-0 shadow-md transition-opacity duration-300 hover:bg-white group-hover:opacity-100 focus-visible:opacity-100"
                :class="{ 'opacity-100': !hasHover }"
                aria-label="Previous photo"
                @click.prevent="prev"
            >
                <ChevronLeft class="h-4 w-4" />
            </button>
            <button
                type="button"
                class="absolute right-2 top-1/2 z-20 flex min-h-[44px] min-w-[44px] -translate-y-1/2 items-center justify-center rounded-full bg-white/85 text-foreground opacity-0 shadow-md transition-opacity duration-300 hover:bg-white group-hover:opacity-100 focus-visible:opacity-100"
                :class="{ 'opacity-100': !hasHover }"
                aria-label="Next photo"
                @click.prevent="next"
            >
                <ChevronRight class="h-4 w-4" />
            </button>
            <!-- Dots + counter (visible on touch, hover on desktop) -->
            <div
                class="absolute bottom-3 left-1/2 z-10 flex -translate-x-1/2 items-center gap-1.5 rounded-full bg-black/50 px-2 py-1 transition-opacity duration-300"
                :class="hasHover ? 'opacity-0 group-hover:opacity-100' : 'opacity-100'"
            >
                <span
                    v-for="(_, i) in photos"
                    :key="i"
                    class="h-1.5 rounded-full transition-all duration-200"
                    :class="i === activeIndex ? 'w-3 bg-white' : 'w-1.5 bg-white/50'"
                />
                <span class="ml-1 text-[10px] font-medium tabular-nums text-white/90">
                    {{ activeIndex + 1 }}/{{ photos.length }}
                </span>
            </div>
        </template>
    </div>
</template>
