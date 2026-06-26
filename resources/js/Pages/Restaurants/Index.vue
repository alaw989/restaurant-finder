<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import RestaurantCard from '@/Components/RestaurantCard.vue';
import RestaurantCardSkeleton from '@/Components/RestaurantCardSkeleton.vue';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    filters: {
        cuisine?: string;
        lat?: string;
        lng?: string;
        sort?: string;
    };
    cuisineName: string | null;
    categorySlug: string | null;
    restaurants: {
        data: Array<{
            id: number;
            name: string;
            slug: string;
            description: string | null;
            address: string | null;
            city: string | null;
            state: string | null;
            lat: number | null;
            lng: number | null;
            photo_url: string | null;
            price_range: string | null;
            phone: string | null;
            website_url: string | null;
            google_rating: number | null;
            google_review_count: number;
            yelp_rating: number | null;
            yelp_review_count: number;
            has_award: boolean;
            popularity_score: number;
            distance: number | null;
            cuisines: Array<{ id: number; name: string; slug: string }>;
            source: string | null;
            score_breakdown: {
                signals: Array<{
                    label: string;
                    weight: number;
                    normalized: number;
                    contribution: number;
                }>;
                total: number;
            };
        }>;
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}>();

const isLoading = ref(false);

// Track Inertia navigation for loading state
router.on('start', () => {
    isLoading.value = true;
});
router.on('finish', () => {
    isLoading.value = false;
});

const sortOptions = [
    { value: 'best_match', label: 'Best Match' },
    { value: 'nearest', label: 'Nearest' },
    { value: 'rating', label: 'Rating' },
    { value: 'reviews', label: 'Reviews' },
    { value: 'price', label: 'Price (Low to High)' },
];

function updateSort(newSort: string) {
    router.get(
        '/restaurants',
        {
            ...props.filters,
            sort: newSort,
        },
        {
            preserveState: true,
            replace: true,
        }
    );
}
</script>

<template>
    <AppLayout>
        <Head :title="`Top ${cuisineName || ''} Restaurants`" />

        <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
            <div class="mb-8">
                <a
                    v-if="categorySlug"
                    :href="`/cuisine/${categorySlug}`"
                    class="text-sm text-muted-foreground hover:text-foreground transition-colors"
                >
                    &larr; Back to categories
                </a>
                <a
                    v-else
                    href="/"
                    class="text-sm text-muted-foreground hover:text-foreground transition-colors"
                >
                    &larr; Back to categories
                </a>
                <div class="mt-4 flex flex-wrap items-center justify-between gap-4">
                    <h1 class="text-2xl font-bold text-foreground sm:text-3xl">
                        Top {{ (cuisineName || 'All').toLowerCase() }} Restaurants
                    </h1>
                    <!-- Sort control -->
                    <div class="flex items-center gap-2">
                        <label for="sort-select" class="text-sm text-muted-foreground">Sort by:</label>
                        <select
                            id="sort-select"
                            :value="filters.sort || 'best_match'"
                            @change="updateSort(($event.target as HTMLSelectElement).value)"
                            class="rounded-md border border-input bg-background px-3 py-1.5 text-sm ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                        >
                            <option v-for="option in sortOptions" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                    </div>
                </div>
                <p class="mt-1 text-muted-foreground">
                    Ranked by popularity score from Google, Yelp, and live busyness data.
                </p>
            </div>

            <!-- Loading skeletons -->
            <div v-if="isLoading" class="grid grid-cols-1 gap-x-5 gap-y-7 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                <RestaurantCardSkeleton v-for="i in 8" :key="'skeleton-' + i" />
            </div>

            <div v-if="!isLoading && restaurants.data.length > 0" class="grid grid-cols-1 gap-x-5 gap-y-7 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                <RestaurantCard
                    v-for="(restaurant, index) in restaurants.data"
                    :key="restaurant.id"
                    :restaurant="restaurant"
                    :rank="(restaurants.current_page - 1) * 20 + index + 1"
                />
            </div>

            <div v-else-if="!isLoading" class="rounded-lg border border-border bg-card p-12 text-center">
                <p class="text-lg text-muted-foreground">
                    No {{ cuisineName || '' }} restaurants found in your area yet.
                </p>
                <p class="mt-2 text-sm text-muted-foreground">
                    Try a different cuisine or location.
                </p>
            </div>

            <div
                v-if="!isLoading && restaurants.last_page > 1"
                class="mt-8 flex items-center justify-center gap-4"
            >
                <Button
                    v-if="restaurants.prev_page_url"
                    variant="outline"
                    size="sm"
                    as="a"
                    :href="restaurants.prev_page_url"
                >
                    Previous
                </Button>
                <span class="text-sm text-muted-foreground">
                    Page {{ restaurants.current_page }} of {{ restaurants.last_page }}
                </span>
                <Button
                    v-if="restaurants.next_page_url"
                    variant="outline"
                    size="sm"
                    as="a"
                    :href="restaurants.next_page_url"
                >
                    Next
                </Button>
            </div>
        </div>
    </AppLayout>
</template>
