import { ref, nextTick, type Ref } from 'vue';
import type { Restaurant } from '@/types/restaurant';

type Phase = 'idle' | 'searching' | 'results' | 'empty' | 'error';

interface SearchParams {
    selectedCuisine?: string;
    selectedCategory?: string;
    lat: Ref<number | null>;
    lng: Ref<number | null>;
    sort: Ref<string>;
}

interface SearchState {
    restaurants: Ref<Restaurant[]>;
    phase: Ref<Phase>;
    shouldStagger: Ref<boolean>;
    isResorting: Ref<boolean>;
    nextPageUrl: Ref<string | null>;
    searchError: Ref<string | null>;
    loadMoreError: Ref<string | null>;
}

/**
 * Composable for restaurant search, resort, and load-more functionality.
 * Manages the search phase machine and provides URLSearchParams builder.
 */
export function useRestaurantSearch(
    setPhase: (phase: Phase) => void,
    getPhase: () => Phase
) {
    const restaurants = ref<Restaurant[]>([]);
    const shouldStagger = ref(false);
    const isResorting = ref(false);
    const nextPageUrl = ref<string | null>(null);
    const searchError = ref<string | null>(null);
    const loadMoreError = ref<string | null>(null);

    /**
     * Build URLSearchParams for search/resort requests.
     * Shared logic between search() and resort().
     */
    function buildSearchParams(params: SearchParams): URLSearchParams {
        const query = new URLSearchParams();
        if (params.selectedCuisine) {
            query.set('cuisine', params.selectedCuisine);
        } else if (params.selectedCategory) {
            query.set('category', params.selectedCategory);
        }
        if (params.lat.value !== null) {
            query.set('lat', params.lat.value.toString());
        }
        if (params.lng.value !== null) {
            query.set('lng', params.lng.value.toString());
        }
        query.set('sort', params.sort.value);
        return query;
    }

    /**
     * Perform a full search with spinner and card stagger.
     * Called on initial search and "Start Over" / "Try Again".
     */
    async function search(params: SearchParams): Promise<void> {
        setPhase('searching');
        searchError.value = null;
        loadMoreError.value = null;

        const query = buildSearchParams(params);

        try {
            const response = await fetch(`/api/restaurants?${query}`);
            if (!response.ok) {
                throw new Error('Search failed');
            }
            const data = await response.json();
            restaurants.value = data.data ?? [];
            nextPageUrl.value = data.next_page_url;

            if (restaurants.value.length === 0) {
                setPhase('empty');
            } else {
                // Arm the stagger for this render only
                shouldStagger.value = true;
                setPhase('results');
                nextTick(() => {
                    shouldStagger.value = false;
                });
            }
            searchError.value = null;
        } catch {
            searchError.value = 'Couldn\'t reach the listing service. Please try again.';
            restaurants.value = [];
            nextPageUrl.value = null;
            setPhase('error');
        }
    }

    /**
     * Re-fetch on sort change WITHOUT the spinner + full card stagger.
     * Same endpoint + query as search(); only the UX wrapper differs
     * (brief grid dim, no phase flip to 'searching', no re-armed stagger).
     * Falls back to a full search if somehow invoked before we have results.
     */
    async function resort(params: SearchParams): Promise<void> {
        const currentPhase = getPhase();
        if (currentPhase !== 'results' && currentPhase !== 'empty') {
            return search(params);
        }

        isResorting.value = true;
        searchError.value = null;
        loadMoreError.value = null;

        const query = buildSearchParams(params);

        try {
            const response = await fetch(`/api/restaurants?${query}`);
            if (!response.ok) {
                throw new Error('Resort failed');
            }
            const data = await response.json();
            restaurants.value = data.data ?? [];
            nextPageUrl.value = data.next_page_url;
            if (restaurants.value.length === 0) {
                setPhase('empty');
            } else {
                setPhase('results');
            }
        } catch {
            searchError.value = 'Couldn\'t reach the listing service. Please try again.';
            restaurants.value = [];
            nextPageUrl.value = null;
            setPhase('error');
        } finally {
            isResorting.value = false;
        }
    }

    /**
     * Load more results from pagination.
     */
    async function loadMore(): Promise<void> {
        if (!nextPageUrl.value || getPhase() !== 'results') return;

        try {
            const response = await fetch(nextPageUrl.value);
            if (!response.ok) {
                throw new Error('Load more failed');
            }
            const data = await response.json();
            restaurants.value.push(...(data.data ?? []));
            nextPageUrl.value = data.next_page_url;
            loadMoreError.value = null;
        } catch {
            loadMoreError.value = 'Couldn\'t load more results. Please try again.';
        }
    }

    /**
     * Reset search state to initial idle state.
     */
    function resetState(): void {
        restaurants.value = [];
        nextPageUrl.value = null;
        searchError.value = null;
        loadMoreError.value = null;
        shouldStagger.value = false;
        isResorting.value = false;
    }

    return {
        restaurants,
        shouldStagger,
        isResorting,
        nextPageUrl,
        searchError,
        loadMoreError,
        search,
        resort,
        loadMore,
        resetState,
    };
}
