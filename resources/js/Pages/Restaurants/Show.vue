<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import StarRating from '@/Components/StarRating.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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

function busynessLabel(value: number): string {
    if (value >= 80) return 'Very Busy';
    if (value >= 60) return 'Busy';
    if (value >= 40) return 'Moderate';
    return 'Quiet';
}

function busynessColor(value: number): string {
    if (value >= 80) return 'bg-red-500';
    if (value >= 60) return 'bg-amber-500';
    if (value >= 40) return 'bg-emerald-500';
    return 'bg-blue-500';
}
</script>

<template>
    <AppLayout>
        <Head :title="restaurant.name" />

        <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <!-- Breadcrumbs -->
            <nav class="flex items-center gap-2 text-sm text-muted-foreground">
                <a href="/" class="hover:text-foreground transition-colors">Categories</a>
                <span>/</span>
                <a
                    v-if="categorySlug"
                    :href="`/cuisine/${categorySlug}`"
                    class="hover:text-foreground transition-colors"
                >
                    Subcategories
                </a>
                <span v-if="categorySlug">/</span>
                <span class="text-foreground">{{ restaurant.name }}</span>
            </nav>

            <!-- Hero photo -->
            <div class="mt-4 overflow-hidden rounded-xl">
                <div
                    v-if="restaurant.photo_url"
                    class="h-64 w-full bg-muted bg-cover bg-center sm:h-80"
                    :style="{ backgroundImage: `url(${restaurant.photo_url})` }"
                />
                <div v-else class="flex h-64 w-full items-center justify-center bg-muted sm:h-80">
                    <span class="text-8xl text-muted-foreground/50">🍽️</span>
                </div>
            </div>

            <div class="mt-6 grid gap-8 lg:grid-cols-3">
                <!-- Main content -->
                <div class="lg:col-span-2">
                    <h1 class="text-3xl font-bold text-foreground">{{ restaurant.name }}</h1>

                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <Badge v-for="cuisine in restaurant.cuisines" :key="cuisine.id" variant="outline">
                            {{ cuisine.name }}
                        </Badge>
                        <span v-if="restaurant.price_range" class="text-sm font-semibold text-foreground">
                            {{ restaurant.price_range }}
                        </span>
                        <span class="text-sm text-muted-foreground">
                            Popularity: {{ (Number(restaurant.popularity_score) * 100).toFixed(0) }}/100
                        </span>
                    </div>

                    <p v-if="restaurant.description" class="mt-4 text-muted-foreground leading-relaxed">
                        {{ restaurant.description }}
                    </p>

                    <Separator class="my-6" />

                    <!-- Ratings -->
                    <h2 class="mb-4 text-lg font-semibold text-foreground">Ratings & Reviews</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <Card v-if="restaurant.google_rating">
                            <CardContent class="flex items-center gap-4 p-4">
                                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-xl font-bold text-blue-600">
                                    G
                                </div>
                                <div>
                                    <p class="text-sm font-medium text-muted-foreground">Google</p>
                                    <div class="flex items-center gap-2">
                                        <StarRating :rating="restaurant.google_rating" size="sm" />
                                    </div>
                                    <p class="mt-0.5 text-xs text-muted-foreground">
                                        {{ restaurant.google_review_count.toLocaleString() }} reviews
                                    </p>
                                </div>
                            </CardContent>
                        </Card>

                        <Card v-if="restaurant.yelp_rating">
                            <CardContent class="flex items-center gap-4 p-4">
                                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-red-50 text-xl font-bold text-red-600">
                                    Y
                                </div>
                                <div>
                                    <p class="text-sm font-medium text-muted-foreground">Yelp</p>
                                    <div class="flex items-center gap-2">
                                        <StarRating :rating="restaurant.yelp_rating" size="sm" />
                                    </div>
                                    <p class="mt-0.5 text-xs text-muted-foreground">
                                        {{ restaurant.yelp_review_count.toLocaleString() }} reviews
                                    </p>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div>

                <!-- Sidebar -->
                <div class="space-y-4">
                    <Card>
                        <CardHeader>
                            <CardTitle>Details</CardTitle>
                        </CardHeader>
                        <CardContent class="flex flex-col gap-4">
                            <div v-if="restaurant.address">
                                <p class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Address</p>
                                <p class="mt-1 text-sm text-foreground">
                                    {{ restaurant.address }}
                                    <span v-if="restaurant.city">, {{ restaurant.city }}</span>
                                    <span v-if="restaurant.state">, {{ restaurant.state }}</span>
                                    <span v-if="restaurant.postal_code"> {{ restaurant.postal_code }}</span>
                                </p>
                            </div>

                            <div v-if="restaurant.phone">
                                <p class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Phone</p>
                                <p class="mt-1 text-sm text-foreground">{{ restaurant.phone }}</p>
                            </div>

                            <div v-if="restaurant.website_url">
                                <p class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Website</p>
                                <a
                                    :href="restaurant.website_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="mt-1 block text-sm text-primary hover:underline break-all"
                                >
                                    {{ restaurant.website_url.replace(/^https?:\/\//, '') }}
                                </a>
                            </div>
                        </CardContent>
                    </Card>

                    <Card v-if="restaurant.popular_times_avg_busyness !== null">
                        <CardHeader>
                            <CardTitle>Busyness</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div class="flex items-end gap-3">
                                <span class="text-3xl font-bold text-foreground">
                                    {{ Math.round(Number(restaurant.popular_times_avg_busyness)) }}%
                                </span>
                                <span class="mb-1 text-sm text-muted-foreground">avg</span>
                            </div>
                            <div class="mt-2 h-3 w-full rounded-full bg-muted">
                                <div
                                    class="h-3 rounded-full transition-all"
                                    :class="busynessColor(Number(restaurant.popular_times_avg_busyness))"
                                    :style="{ width: `${restaurant.popular_times_avg_busyness}%` }"
                                />
                            </div>
                            <p class="mt-2 text-sm text-muted-foreground">
                                {{ busynessLabel(Number(restaurant.popular_times_avg_busyness)) }} on average
                            </p>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
