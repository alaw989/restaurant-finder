import { describe, it, expect, beforeEach, vi } from 'vitest';
import { reactive } from 'vue';
import type { Restaurant } from '@/types/restaurant';

// Hoisted so the vi.mock factory (which runs before imports) closes over a
// stable, mutable page + router.post. Each test reshapes `page.props.auth` to
// flip between the guest and authed branches.
const inertia = vi.hoisted(() => ({
    page: {
        props: {
            auth: undefined as { user?: { id: number }; favorites?: number[] } | undefined,
        },
    },
    post: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => inertia.page,
    router: { post: (...args: unknown[]) => inertia.post(...(args as Parameters<typeof vi.fn>)) },
}));

import { useFavorites } from '@/composables/useFavorites';

const STORAGE_KEY = 'ipop360_favorites';

function makeVenue(overrides: Partial<Restaurant> = {}): Restaurant {
    return {
        id: 1,
        name: 'Casa Garcia',
        slug: 'casa-garcia',
        description: null,
        address: '123 Main',
        city: 'Austin',
        state: 'TX',
        lat: 30.27,
        lng: -97.74,
        photo_url: null,
        price_range: '$$',
        phone: '512-555-1212',
        website_url: null,
        google_rating: 4.5,
        google_review_count: 100,
        yelp_rating: null,
        yelp_review_count: 0,
        has_award: false,
        popularity_score: 0.7,
        distance: 1.2,
        cuisines: [],
        source: 'serpapi',
        ...overrides,
    };
}

function readLocalKeys(): string[] {
    const raw = localStorage.getItem(STORAGE_KEY);
    return raw ? (JSON.parse(raw) as Array<{ key: string }>).map((f) => f.key) : [];
}

beforeEach(() => {
    localStorage.clear();
    // Real Inertia returns a REACTIVE page; use a reactive stub so Vue's
    // computed(() => page.props.auth?.favorites) actually re-tracks the
    // optimistic mutations toggle() makes (a plain object would cache stale).
    inertia.page = reactive({
        props: {
            auth: undefined as { user?: { id: number }; favorites?: number[] } | undefined,
        },
    });
    inertia.post.mockReset();
});

describe('useFavorites — guest (localStorage) path', () => {
    it('starts empty', () => {
        const { isFavorited, hasLocalFavorites } = useFavorites();
        expect(isFavorited(makeVenue())).toBe(false);
        expect(hasLocalFavorites()).toBe(false);
    });

    it('toggles a venue into localStorage and back out', async () => {
        const { toggle, isFavorited, hasLocalFavorites } = useFavorites();
        const venue = makeVenue({ google_place_id: 'GP1' });

        await toggle(venue);
        expect(isFavorited(venue)).toBe(true);
        expect(hasLocalFavorites()).toBe(true);
        expect(readLocalKeys()).toEqual(['gp:GP1']);

        await toggle(venue);
        expect(isFavorited(venue)).toBe(false);
        expect(hasLocalFavorites()).toBe(false);
        expect(readLocalKeys()).toEqual([]);
    });

    it('getFavoriteKey prefers google_place_id, then slug, then name:city', async () => {
        // google_place_id wins
        await useFavorites().toggle(makeVenue({ google_place_id: 'GP1', slug: 's1', name: 'N', city: 'C' }));
        expect(readLocalKeys()).toContain('gp:GP1');
        localStorage.clear();

        // slug when no place id
        await useFavorites().toggle(makeVenue({ slug: 's1', name: 'N', city: 'C' }));
        expect(readLocalKeys()).toContain('slug:s1');
        localStorage.clear();

        // name:city fallback for live results with neither
        await useFavorites().toggle(makeVenue({ slug: '', name: 'N', city: 'C' } as Partial<Restaurant>));
        expect(readLocalKeys()).toContain('name:N:C');
    });

    it('getLocalFavoritesForMerge splits persisted ids from un-persisted venues', async () => {
        const favs = useFavorites();
        await favs.toggle(makeVenue({ id: 5, slug: 'a' })); // persisted id
        await favs.toggle(makeVenue({ id: -7, slug: 'b' })); // negative id → live/un-persisted

        const { ids, venues } = favs.getLocalFavoritesForMerge();
        expect(ids).toEqual([5]);
        expect(venues.map((v) => v.slug)).toEqual(['b']);
    });

    it('clearLocalFavorites empties state and storage', async () => {
        const favs = useFavorites();
        await favs.toggle(makeVenue({ slug: 'a' }));
        expect(favs.hasLocalFavorites()).toBe(true);

        favs.clearLocalFavorites();
        expect(favs.hasLocalFavorites()).toBe(false);
        expect(localStorage.getItem(STORAGE_KEY)).toBeNull();
    });
});

describe('useFavorites — authed (server) path', () => {
    beforeEach(() => {
        inertia.page.props.auth = { user: { id: 1 }, favorites: [5] };
    });

    it('membership is by server id', () => {
        const { isFavorited } = useFavorites();
        expect(isFavorited(makeVenue({ id: 5 }))).toBe(true);
        expect(isFavorited(makeVenue({ id: 6 }))).toBe(false);
    });

    it('toggle optimistically adds and posts to /favorites/toggle', async () => {
        const { toggle, isFavorited } = useFavorites();
        const venue = makeVenue({ id: 6 });

        await toggle(venue);

        expect(isFavorited(venue)).toBe(true);
        expect(inertia.page.props.auth?.favorites).toEqual([5, 6]);
        expect(inertia.post).toHaveBeenCalledWith(
            '/favorites/toggle',
            { restaurant: venue, id: 6 },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('toggle optimistically removes', async () => {
        const { toggle, isFavorited } = useFavorites();
        const venue = makeVenue({ id: 5 });

        await toggle(venue);

        expect(isFavorited(venue)).toBe(false);
        expect(inertia.page.props.auth?.favorites).toEqual([]);
    });
});
