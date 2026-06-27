<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import StarRating from '@/Components/StarRating.vue';
import ScoreBreakdown from '@/Components/ScoreBreakdown.vue';
import DetailMap from '@/Components/DetailMap.vue';
import CardGallery from '@/Components/CardGallery.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { cuisineGradient } from '@/lib/cuisine';
import { callPhone, openWebsite, directionsUrl } from '@/lib/restaurant';
import { Heart, ArrowLeft, MapPin, Navigation, Phone, Globe } from '@lucide/vue';
import { useFavorites } from '@/composables/useFavorites';
import { useSeo, generateRestaurantJsonLd } from '@/composables/useSeo';
import { useBaseUrl } from '@/composables/useBaseUrl';
import JsonLd from '@/Components/JsonLd.vue';
import SeoMeta from '@/Components/SeoMeta.vue';
import type { Restaurant } from '@/types/restaurant';

const props = defineProps<{
    categorySlug: string | null;
    canonicalUrl?: string | null;
    isLivePreview?: boolean;
    restaurant: Restaurant;
}>();

const { isFavorited, toggle } = useFavorites();

const saved = computed(() => isFavorited(props.restaurant));
const ariaLabel = computed(() => (saved.value ? 'Saved' : 'Save restaurant'));

const photos = computed(() =>
    Array.from(
        new Set(
            [props.restaurant.photo_url, ...(props.restaurant.photos ?? [])].filter(
                Boolean,
            ) as string[],
        ),
    ),
);

const gradient = computed(() =>
    cuisineGradient(props.restaurant.cuisines[0]?.slug),
);

// SEO
const baseUrl = useBaseUrl()

const cuisineNames = computed(() =>
    props.restaurant.cuisines.map(c => c.name).join(', ')
)

const seoData = computed(() => {
    const title = `${props.restaurant.name} | ${cuisineNames.value} in ${props.restaurant.city || 'Your Area'} | iPop360`
    const description = props.restaurant.description
        ? `${props.restaurant.description.substring(0, 160)}${props.restaurant.description.length > 160 ? '...' : ''}`
        : `Visit ${props.restaurant.name} for ${cuisineNames.value.toLowerCase()} cuisine in ${props.restaurant.city || 'your area'}. View ratings, reviews, photos, and more.`

    const restaurantUrl = props.canonicalUrl ?? `${baseUrl.value}/restaurants/${props.restaurant.slug}`;

    return useSeo({
        title,
        description,
        url: restaurantUrl,
        image: photos.value[0] || undefined,
        type: 'restaurant',
        noindex: props.isLivePreview === true,
    })
})

const structuredData = computed(() => {
    const restaurantData = {
        name: props.restaurant.name,
        url: props.canonicalUrl ?? `${baseUrl.value}/restaurants/${props.restaurant.slug}`,
        address: props.restaurant.address,
        city: props.restaurant.city,
        state: props.restaurant.state,
        latitude: props.restaurant.lat,
        longitude: props.restaurant.lng,
        phone: props.restaurant.phone,
        google_rating: props.restaurant.google_rating,
        google_review_count: props.restaurant.google_review_count,
        cuisines: props.restaurant.cuisines,
        price_range: props.restaurant.price_range,
    }

    return generateRestaurantJsonLd(restaurantData)
})
</script>

<template>
    <AppLayout>
        <SeoMeta :seoData="seoData" />
        <link
            v-if="photos.length > 0"
            rel="preload"
            as="image"
            :href="photos[0]"
            fetchpriority="high"
        />

        <!-- Structured data — Inertia <Head> drops <script> tags, so inject via JsonLd -->
        <JsonLd :data="structuredData" />

        <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <!-- Back link -->
            <a
                v-if="categorySlug"
                :href="`/restaurants?cuisine=${restaurant.cuisines[0]?.slug ?? ''}`"
                class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground transition-colors"
            >
                <ArrowLeft :size="14" />
                Back to results
            </a>
            <a
                v-else
                href="/restaurants"
                class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground transition-colors"
            >
                <ArrowLeft :size="14" />
                Back to results
            </a>

            <!-- Hero -->
            <div class="mt-4 grid gap-8 lg:grid-cols-5">
                <div class="lg:col-span-3">
                    <CardGallery
                        :photos="photos"
                        :gradient="gradient"
                        :alt="restaurant.name"
                        aspect="3/2"
                        :multi="false"
                        :eager="true"
                        rounded-class="rounded-xl"
                    />
                </div>

                <!-- Info sidebar -->
                <div class="lg:col-span-2 lg:pt-0">
                    <div class="flex items-start gap-3">
                        <h1 class="text-2xl font-bold text-foreground sm:text-3xl">{{ restaurant.name }}</h1>
                        <div v-if="restaurant.has_award" class="shrink-0">
                            <div class="flex h-10 w-10 items-center justify-center rounded-full bg-amber-400/20">
                                <span class="text-lg" title="Award-winning">⭐</span>
                            </div>
                        </div>
                        <button
                            class="ml-auto flex h-10 w-10 items-center justify-center rounded-full bg-muted/50 text-foreground shadow-md ring-2 ring-white/50 transition-all hover:bg-muted hover:scale-110"
                            :class="{ 'text-red-500 fill-red-500': saved }"
                            :aria-label="ariaLabel"
                            @click="() => toggle(restaurant)"
                        >
                            <Heart
                                class="h-5 w-5"
                                :class="saved ? 'fill-current' : 'fill-none stroke-current'"
                            />
                        </button>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <Badge v-for="cuisine in restaurant.cuisines" :key="cuisine.id" variant="secondary" class="bg-primary/5 text-primary/70">
                            {{ cuisine.name }}
                        </Badge>
                        <span v-if="restaurant.price_range" class="text-sm font-semibold text-emerald-500 dark:text-emerald-400">
                            {{ restaurant.price_range }}
                        </span>
                    </div>

                    <p v-if="restaurant.description" class="mt-4 leading-relaxed text-muted-foreground">
                        {{ restaurant.description }}
                    </p>

                    <!-- Ratings -->
                    <div class="mt-5 space-y-3">
                        <Card v-if="restaurant.yelp_rating || restaurant.google_rating">
                            <CardContent class="p-4">
                                <div class="space-y-2">
                                    <div v-if="restaurant.yelp_rating" class="flex items-center justify-between">
                                        <span class="text-xs font-medium text-muted-foreground">Yelp</span>
                                        <div class="flex items-center gap-2">
                                            <StarRating :rating="restaurant.yelp_rating" size="sm" />
                                            <span class="text-sm tabular-nums text-muted-foreground">({{ restaurant.yelp_review_count }})</span>
                                        </div>
                                    </div>
                                    <div v-if="restaurant.google_rating" class="flex items-center justify-between">
                                        <span class="text-xs font-medium text-muted-foreground">Google</span>
                                        <div class="flex items-center gap-2">
                                            <StarRating :rating="restaurant.google_rating" size="sm" />
                                            <span class="text-sm tabular-nums text-muted-foreground">({{ restaurant.google_review_count }})</span>
                                        </div>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    <!-- Details -->
                    <div class="mt-5 space-y-2.5">
                        <div v-if="restaurant.address" class="flex items-start gap-2.5 text-sm">
                            <MapPin :size="16" class="mt-0.5 shrink-0 text-muted-foreground" />
                            <span class="text-muted-foreground">
                                {{ restaurant.address }}<span v-if="restaurant.city">, {{ restaurant.city }}</span><span v-if="restaurant.state">, {{ restaurant.state }}</span><span v-if="restaurant.postal_code"> {{ restaurant.postal_code }}</span>
                            </span>
                        </div>

                        <a
                            v-if="restaurant.lat && restaurant.lng"
                            :href="directionsUrl(restaurant.lat, restaurant.lng)"
                            target="_blank"
                            rel="noopener"
                            class="flex items-center gap-2.5 text-sm text-muted-foreground hover:text-primary transition-colors"
                        >
                            <Navigation :size="16" class="shrink-0" />
                            Get directions
                        </a>

                        <button
                            v-if="restaurant.phone"
                            class="flex w-full items-center gap-2.5 text-sm text-muted-foreground hover:text-primary transition-colors"
                            @click="() => callPhone(restaurant.phone!)"
                        >
                            <Phone :size="16" class="shrink-0" />
                            {{ restaurant.phone }}
                        </button>

                        <button
                            v-if="restaurant.website_url"
                            class="flex w-full items-center gap-2.5 text-sm text-muted-foreground hover:text-primary transition-colors"
                            @click="() => openWebsite(restaurant.website_url!)"
                        >
                            <Globe :size="16" class="shrink-0" />
                            {{ restaurant.website_url.replace(/^https?:\/\//, '') }}
                        </button>
                    </div>

                    <!-- Score -->
                    <div v-if="restaurant.score_breakdown" class="mt-5">
                        <h3 class="mb-2 text-xs font-medium uppercase tracking-wider text-muted-foreground">Popularity Score</h3>
                        <ScoreBreakdown :breakdown="restaurant.score_breakdown" />
                    </div>
                </div>
            </div>

            <!-- Map section -->
            <div v-if="restaurant.lat && restaurant.lng" class="mt-8">
                <h2 class="mb-3 text-lg font-semibold text-foreground">Location</h2>
                <DetailMap
                    :lat="restaurant.lat"
                    :lng="restaurant.lng"
                    :name="restaurant.name"
                    :address="restaurant.address"
                />
            </div>
        </div>
    </AppLayout>
</template>
