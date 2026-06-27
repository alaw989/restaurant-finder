import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import type { Restaurant } from '@/types/restaurant';

const STORAGE_KEY = 'ipop360_favorites';

interface LocalFavorite {
    id?: number;
    key: string;
    venue: Restaurant;
}

interface PageProps {
    auth?: {
        user?: any;
        favorites?: number[];
    };
}

/**
 * Composable for managing restaurant favorites with hybrid persistence:
 * - Authed users: server-side with optimistic updates
 * - Guests: localStorage with login hint
 */
export function useFavorites() {
    const page = usePage();
    const authUser = computed(() => (page.props as PageProps).auth?.user);

    // Read initial state from props (authed) or localStorage (guest)
    const serverFavoriteIds = computed(() => {
        const favorites = (page.props as PageProps).auth?.favorites;
        return favorites ?? [];
    });

    const localFavorites = ref<LocalFavorite[]>([]);

    // Initialize from localStorage only on client
    if (typeof window !== 'undefined') {
        try {
            const stored = localStorage.getItem(STORAGE_KEY);
            if (stored) {
                localFavorites.value = JSON.parse(stored);
            }
        } catch {
            localFavorites.value = [];
        }
    }

    const localFavoriteKeys = computed(() =>
        new Set(localFavorites.value.map((f) => f.key))
    );

    /**
     * Check if a restaurant is favorited.
     */
    function isFavorited(restaurant: Restaurant): boolean {
        if (authUser.value) {
            return serverFavoriteIds.value.includes(restaurant.id);
        }

        // For guests, check by unique key (google_place_id or slug fallback)
        const key = getFavoriteKey(restaurant);
        return localFavoriteKeys.value.has(key);
    }

    /**
     * Generate a unique key for a restaurant (used for localStorage dedup).
     * Prefers google_place_id, falls back to slug, then a generated key.
     */
    function getFavoriteKey(restaurant: Restaurant): string {
        if (restaurant.google_place_id) {
            return `gp:${restaurant.google_place_id}`;
        }
        if (restaurant.slug) {
            return `slug:${restaurant.slug}`;
        }
        // Fallback: use name+city for live results without slug
        return `name:${restaurant.name}:${restaurant.city || ''}`;
    }

    /**
     * Toggle a restaurant's favorite status.
     */
    async function toggle(restaurant: Restaurant) {
        if (authUser.value) {
            // Authed: server-side toggle with optimistic update
            const wasFavorited = serverFavoriteIds.value.includes(restaurant.id);

            // Optimistic update
            const newFavorites = wasFavorited
                ? serverFavoriteIds.value.filter((id) => id !== restaurant.id)
                : [...serverFavoriteIds.value, restaurant.id];

            // Update props optimistically (will be reloaded from server on response)
            if ((page.props as PageProps).auth) {
                (page.props as PageProps).auth!.favorites = newFavorites;
            }

            try {
                await router.post(
                    '/favorites/toggle',
                    {
                        restaurant,
                        id: restaurant.id,
                    } as any,
                    {
                        preserveScroll: true,
                        onError: () => {
                            // Rollback on error
                            if ((page.props as PageProps).auth) {
                                (page.props as PageProps).auth!.favorites = serverFavoriteIds.value;
                            }
                        },
                    }
                );
            } catch (error) {
                // Rollback on network error
                if ((page.props as PageProps).auth) {
                    (page.props as PageProps).auth!.favorites = serverFavoriteIds.value;
                }
                throw error;
            }
        } else {
            // Guest: localStorage toggle with login hint
            const key = getFavoriteKey(restaurant);
            const idx = localFavorites.value.findIndex((f) => f.key === key);

            if (idx >= 0) {
                // Remove
                localFavorites.value.splice(idx, 1);
            } else {
                // Add
                localFavorites.value.push({
                    id: restaurant.id,
                    key,
                    venue: restaurant,
                });
            }

            // Persist to localStorage
            try {
                localStorage.setItem(STORAGE_KEY, JSON.stringify(localFavorites.value));
            } catch (e) {
                console.error('Failed to save favorites to localStorage', e);
            }

        }
    }

    /**
     * Get the current list of favorite restaurants for merge-on-login.
     */
    function getLocalFavoritesForMerge(): {
        ids: number[];
        venues: Restaurant[];
    } {
        const ids: number[] = [];
        const venues: Restaurant[] = [];

        for (const fav of localFavorites.value) {
            if (fav.id && fav.id > 0) {
                ids.push(fav.id);
            } else {
                venues.push(fav.venue);
            }
        }

        return { ids, venues };
    }

    /**
     * Clear local favorites (called after successful merge on login).
     */
    function clearLocalFavorites(): void {
        localFavorites.value = [];
        try {
            localStorage.removeItem(STORAGE_KEY);
        } catch (e) {
            console.error('Failed to clear favorites from localStorage', e);
        }
    }

    /**
     * Check if there are local favorites to merge.
     */
    function hasLocalFavorites(): boolean {
        return localFavorites.value.length > 0;
    }

    return {
        isFavorited,
        toggle,
        getLocalFavoritesForMerge,
        clearLocalFavorites,
        hasLocalFavorites,
        serverFavoriteIds,
    };
}
