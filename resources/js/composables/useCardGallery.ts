import { computed, ref } from 'vue';

/**
 * Photo-swap state for a result card's image region (Airbnb-style):
 * cursor-X over the photo maps to an index (desktop), chevrons step one,
 * mouseleave resets to the hero. Pure state — the owning component wires
 * the DOM events and renders the image stack.
 *
 * @param getPhotos getter for the ordered photo URLs (hero first)
 */
export function useCardGallery(getPhotos: () => string[]) {
    const photos = computed(getPhotos);
    const activeIndex = ref(0);
    const isMulti = computed(() => photos.value.length > 1);

    let raf = 0;
    let pending = 0;

    function onMove(e: MouseEvent) {
        if (!isMulti.value) return;
        const el = e.currentTarget as HTMLElement | null;
        if (!el) return;
        const rect = el.getBoundingClientRect();
        const x = (e.clientX - rect.left) / rect.width; // 0..1
        const len = photos.value.length;
        pending = Math.min(len - 1, Math.max(0, Math.floor(x * len)));

        if (typeof requestAnimationFrame === 'undefined') {
            activeIndex.value = pending;
            return;
        }
        if (!raf) {
            raf = requestAnimationFrame(() => {
                raf = 0;
                activeIndex.value = pending;
            });
        }
    }

    function onLeave() {
        activeIndex.value = 0;
    }

    function goTo(i: number) {
        if (!isMulti.value) return;
        const len = photos.value.length;
        activeIndex.value = ((i % len) + len) % len;
    }

    const prev = () => goTo(activeIndex.value - 1);
    const next = () => goTo(activeIndex.value + 1);

    return { activeIndex, isMulti, onMove, onLeave, prev, next, goTo };
}
