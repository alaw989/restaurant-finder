<script setup lang="ts">
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import StarRating from '@/Components/StarRating.vue';
import PopularityBadge from '@/Components/PopularityBadge.vue';
import { Link } from '@inertiajs/vue3';

defineProps<{
    restaurant: {
        id: number;
        name: string;
        slug: string;
        description: string | null;
        address: string | null;
        city: string | null;
        photo_url: string | null;
        price_range: string | null;
        google_rating: number | null;
        google_review_count: number;
        yelp_rating: number | null;
        yelp_review_count: number;
        popularity_score: number;
        distance: number | null;
        cuisines: Array<{ id: number; name: string; slug: string }>;
    };
    rank: number;
}>();
</script>

<template>
    <Link :href="`/restaurants/${restaurant.slug}`">
        <Card class="group overflow-hidden transition-all hover:shadow-lg">
            <div class="flex flex-col sm:flex-row">
                <div
                    v-if="restaurant.photo_url"
                    class="h-48 w-full sm:h-auto sm:w-48 shrink-0 bg-muted bg-cover bg-center"
                    :style="{ backgroundImage: `url(${restaurant.photo_url})` }"
                />
                <div
                    v-else
                    class="flex h-32 w-full sm:h-auto sm:w-48 shrink-0 items-center justify-center bg-muted"
                >
                    <span class="text-4xl text-muted-foreground">🍽️</span>
                </div>

                <CardContent class="flex flex-1 flex-col gap-2 p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <h3 class="truncate text-lg font-semibold text-foreground group-hover:text-primary transition-colors">
                                {{ restaurant.name }}
                            </h3>
                            <p v-if="restaurant.address" class="truncate text-sm text-muted-foreground">
                                {{ restaurant.city ? `${restaurant.city}, ` : '' }}{{ restaurant.address }}
                            </p>
                        </div>
                        <PopularityBadge :rank="rank" />
                    </div>

                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <StarRating
                            v-if="restaurant.google_rating"
                            :rating="restaurant.google_rating"
                            size="sm"
                        />
                        <span class="text-sm text-muted-foreground">
                            {{ restaurant.google_review_count.toLocaleString() }} reviews
                        </span>
                        <span v-if="restaurant.price_range" class="text-sm font-medium text-foreground">
                            {{ restaurant.price_range }}
                        </span>
                        <span v-if="restaurant.distance != null" class="text-sm text-muted-foreground">
                            {{ Number(restaurant.distance).toFixed(1) }} km
                        </span>
                    </div>

                    <div class="flex flex-wrap gap-1.5">
                        <Badge
                            v-for="cuisine in restaurant.cuisines"
                            :key="cuisine.id"
                            variant="outline"
                            class="text-xs"
                        >
                            {{ cuisine.name }}
                        </Badge>
                    </div>
                </CardContent>
            </div>
        </Card>
    </Link>
</template>
