<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import StarRating from '@/Components/StarRating.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';

defineProps<{
    categorySlug: string | null;
    restaurant: {
        id: number;
        name: string;
        slug: string;
        description: string | null;
        address: string | null;
        city: string | null;
        state: string | null;
        postal_code: string | null;
        phone: string | null;
        website_url: string | null;
        price_range: string | null;
        photo_url: string | null;
        google_rating: number | null;
        google_review_count: number;
        yelp_rating: number | null;
        yelp_review_count: number;
        popular_times_avg_busyness: number | null;
        popularity_score: number;
        cuisines: Array<{ id: number; name: string; slug: string }>;
    };
}>();
</script>

<template>
    <AppLayout>
        <Head :title="restaurant.name" />

        <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <!-- Back link -->
            <a
                v-if="categorySlug"
                :href="`/restaurants?cuisine=${restaurant.cuisines[0]?.slug ?? ''}`"
                class="text-sm text-muted-foreground hover:text-foreground transition-colors"
            >
                &larr; Back to results
            </a>
            <a
                v-else
                href="/restaurants"
                class="text-sm text-muted-foreground hover:text-foreground transition-colors"
            >
                &larr; Back to results
            </a>

            <!-- Hero photo -->
            <div class="mt-4 overflow-hidden rounded-lg">
                <div
                    v-if="restaurant.photo_url"
                    class="h-64 w-full bg-muted bg-cover bg-center sm:h-80"
                    :style="{ backgroundImage: `url(${restaurant.photo_url})` }"
                />
                <div v-else class="flex h-64 w-full items-center justify-center bg-muted sm:h-80">
                    <span class="text-8xl text-muted-foreground/50">🍽️</span>
                </div>
            </div>

            <div class="mt-6">
                <h1 class="text-3xl font-bold text-foreground">{{ restaurant.name }}</h1>

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <Badge v-for="cuisine in restaurant.cuisines" :key="cuisine.id" variant="outline">
                        {{ cuisine.name }}
                    </Badge>
                    <span v-if="restaurant.price_range" class="text-sm font-semibold text-foreground">
                        {{ restaurant.price_range }}
                    </span>
                </div>

                <p v-if="restaurant.description" class="mt-4 text-muted-foreground leading-relaxed">
                    {{ restaurant.description }}
                </p>

                <Separator class="my-6" />

                <!-- Ratings -->
                <div class="grid gap-4 sm:grid-cols-2">
                    <Card v-if="restaurant.google_rating">
                        <CardContent class="p-4">
                            <p class="text-sm font-medium text-muted-foreground">Google</p>
                            <div class="mt-2 flex items-center gap-2">
                                <StarRating :rating="restaurant.google_rating" size="sm" />
                                <span class="text-sm font-medium text-foreground">{{ restaurant.google_rating }}</span>
                            </div>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ restaurant.google_review_count }} reviews
                            </p>
                        </CardContent>
                    </Card>

                    <Card v-if="restaurant.yelp_rating">
                        <CardContent class="p-4">
                            <p class="text-sm font-medium text-muted-foreground">Yelp</p>
                            <div class="mt-2 flex items-center gap-2">
                                <StarRating :rating="restaurant.yelp_rating" size="sm" />
                                <span class="text-sm font-medium text-foreground">{{ restaurant.yelp_rating }}</span>
                            </div>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ restaurant.yelp_review_count }} reviews
                            </p>
                        </CardContent>
                    </Card>
                </div>

                <Separator class="my-6" />

                <!-- Details -->
                <div v-if="restaurant.address">
                    <h2 class="mb-2 text-sm font-medium text-muted-foreground">Details</h2>
                    <p class="text-sm text-foreground">
                        {{ restaurant.address }}<span v-if="restaurant.city">, {{ restaurant.city }}</span><span v-if="restaurant.state">, {{ restaurant.state }}</span><span v-if="restaurant.postal_code"> {{ restaurant.postal_code }}</span>
                    </p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
