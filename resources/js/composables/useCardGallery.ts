import { computed, onMounted, onUnmounted, ref } from 'vue';

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

    // Cache the photo frame's rect so we don't force a layout on every mousemove
    // (mousemove fires 60-120×/s during a hover-scrub). Read once per element,
    // invalidated on enter and whenever the page geometry can shift (scroll/resize).
    let cachedEl: Element | null = null;
    let cachedRect: DOMRect | null = null;

    function invalidate() {
        cachedRect = null;
    }

    function readRect(el: Element): DOMRect {
        if (cachedEl !== el) {
            cachedEl = el;
            cachedRect = null;
        }
        if (!cachedRect) {
            cachedRect = el.getBoundingClientRect();
        }
        return cachedRect;
    }

    onMounted(() => {
        window.addEventListener('scroll', invalidate, { passive: true });
        window.addEventListener('resize', invalidate);
    });

    onUnmounted(() => {
        window.removeEventListener('scroll', invalidate);
        window.removeEventListener('resize', invalidate);
    });

    function onMove(e: MouseEvent) {
        if (!isMulti.value) return;
        const el = e.currentTarget as Element | null;
        if (!el) return;
        const rect = readRect(el);
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

    // Fresh rect read on each hover-in; mouseleave resets the hero.
    function onEnter() {
        invalidate();
    }

    function onLeave() {
        invalidate();
        activeIndex.value = 0;
    }

    function goTo(i: number) {
        if (!isMulti.value) return;
        const len = photos.value.length;
        activeIndex.value = ((i % len) + len) % len;
    }

    const prev = () => goTo(activeIndex.value - 1);
    const next = () => goTo(activeIndex.value + 1);

    return { activeIndex, isMulti, onMove, onEnter, onLeave, prev, next, goTo };
}
