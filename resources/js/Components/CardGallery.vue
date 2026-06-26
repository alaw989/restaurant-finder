<script setup lang="ts">
import { computed, ref, watch } from 'vue';
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
    }>(),
    { aspect: '4/3', multi: true, roundedClass: 'rounded-t-2xl' },
);

const { activeIndex, onMove, onLeave, prev, next } = useCardGallery(() => props.photos);

const galleryActive = computed(() => props.multi && props.photos.length > 1);

const aspectClass = computed(() =>
    props.aspect === '3/2' ? 'aspect-[3/2]' : 'aspect-[4/3]',
);

// Blur-up: veil the gradient until the hero photo actually loads.
const heroLoaded = ref(false);
const showVeil = computed(() => props.photos.length > 0 && !heroLoaded.value);
watch(
    () => props.photos[0],
    () => {
        heroLoaded.value = false;
    },
);
</script>

<template>
    <div
        class="relative w-full overflow-hidden"
        :class="[aspectClass, roundedClass]"
        @mousemove="galleryActive && onMove($event)"
        @mouseleave="galleryActive && onLeave()"
    >
        <!-- cuisine gradient backdrop (perceived-perf + graceful no-photo) -->
        <div class="absolute inset-0" :style="{ background: gradient }" />

        <!-- image stack: crossfade between photos via opacity -->
        <img
            v-for="(src, i) in photos"
            :key="src + '-' + i"
            :src="src"
            :alt="i === 0 ? alt : ''"
            loading="lazy"
            decoding="async"
            class="absolute inset-0 h-full w-full object-cover transition-opacity duration-300 ease-out will-change-[opacity]"
            :class="i === activeIndex ? 'opacity-100' : 'opacity-0'"
            @load="i === 0 ? (heroLoaded = true) : undefined"
            @error="i === 0 ? (heroLoaded = true) : undefined"
        />

        <!-- blur-up veil over the gradient until the hero resolves -->
        <div
            v-show="showVeil"
            class="pointer-events-none absolute inset-0 bg-background/20 backdrop-blur-xl transition-opacity duration-500"
        />

        <!-- bottom readability scrim -->
        <div
            class="pointer-events-none absolute inset-x-0 bottom-0 h-20 bg-gradient-to-t from-black/55 to-transparent"
        />

        <!-- card-specific overlays (rank, award, score, heart) -->
        <slot name="overlays" />

        <!-- gallery controls (desktop hover; tap-cycle on touch via chevrons) -->
        <template v-if="galleryActive">
            <button
                type="button"
                class="absolute left-2 top-1/2 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-full bg-white/85 text-foreground opacity-0 shadow-md transition-opacity duration-300 hover:bg-white group-hover:opacity-100 focus-visible:opacity-100"
                aria-label="Previous photo"
                @click.prevent="prev"
            >
                <ChevronLeft class="h-4 w-4" />
            </button>
            <button
                type="button"
                class="absolute right-2 top-1/2 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-full bg-white/85 text-foreground opacity-0 shadow-md transition-opacity duration-300 hover:bg-white group-hover:opacity-100 focus-visible:opacity-100"
                aria-label="Next photo"
                @click.prevent="next"
            >
                <ChevronRight class="h-4 w-4" />
            </button>
            <div
                class="absolute bottom-3 left-1/2 z-10 flex -translate-x-1/2 items-center gap-1.5 rounded-full bg-black/35 px-2 py-1 opacity-0 backdrop-blur-sm transition-opacity duration-300 group-hover:opacity-100"
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
