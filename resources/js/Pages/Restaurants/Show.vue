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
import { Heart } from '@lucide/vue';
import { useFavorites } from '@/composables/useFavorites';
import { useSeo, generateRestaurantJsonLd } from '@/composables/useSeo';
import JsonLd from '@/Components/JsonLd.vue';

const props = defineProps<{
    categorySlug: string | null;
    canonicalUrl?: string | null;
    isLivePreview?: boolean;
    restaurant: {
        id: number;
        name: string;
        slug: string;
        description: string | null;
        address: string | null;
        city: string | null;
        state: string | null;
        postal_code: string | null;
        lat: number | null;
        lng: number | null;
        photo_url: string | null;
        photos?: Array<string | null>;
        price_range: string | null;
        phone: string | null;
        website_url: string | null;
        google_rating: number | null;
        google_review_count: number;
        yelp_rating: number | null;
        yelp_review_count: number;
        popular_times_avg_busyness: number | null;
        has_award: boolean;
        popularity_score: number;
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
    };
}>();

const { isFavorited, toggle } = useFavorites();

const saved = computed(() => isFavorited(props.restaurant as any));
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
const baseUrl = computed(() => {
    if (typeof window !== 'undefined') {
        return `${window.location.protocol}//${window.location.host}`
    }
    return 'https://ipop360.vp-associates.com'
})

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

function callPhone(phone: string) {
    window.location.href = `tel:${phone}`;
}

function openWebsite(url: string) {
    if (!url.startsWith('http')) url = 'https://' + url;
    window.open(url, '_blank');
}
</script>

<template>
    <AppLayout>
        <Head>
            <title>{{ seoData.title }}</title>
            <meta name="description" :content="seoData.description" />
            <meta v-if="seoData.noindex" name="robots" content="noindex" />
            <link rel="canonical" :href="seoData.canonical" />
            <link
                v-if="photos.length > 0"
                rel="preload"
                as="image"
                :href="photos[0]"
                fetchpriority="high"
            />
            <meta property="og:title" :content="seoData.ogTitle" />
            <meta property="og:description" :content="seoData.ogDescription" />
            <meta property="og:type" :content="seoData.ogType" />
            <meta property="og:url" :content="seoData.ogUrl" />
            <meta property="og:site_name" :content="seoData.ogSiteName" />
            <meta property="og:image" :content="seoData.ogImage" />
            <meta property="og:image:alt" :content="seoData.ogImageAlt" />
            <meta name="twitter:card" :content="seoData.twitterCard" />
            <meta name="twitter:title" :content="seoData.twitterTitle" />
            <meta name="twitter:description" :content="seoData.twitterDescription" />
            <meta name="twitter:image" :content="seoData.twitterImage" />
        </Head>

        <!-- Structured data — Inertia <Head> drops <script> tags, so inject via JsonLd -->
        <JsonLd :data="structuredData" />

        <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <!-- Back link -->
            <a
                v-if="categorySlug"
                :href="`/restaurants?cuisine=${restaurant.cuisines[0]?.slug ?? ''}`"
                class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground transition-colors"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/></svg>
                Back to results
            </a>
            <a
                v-else
                href="/restaurants"
                class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground transition-colors"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/></svg>
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
                            @click="() => toggle(restaurant as any)"
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
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-0.5 shrink-0 text-muted-foreground"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                            <span class="text-muted-foreground">
                                {{ restaurant.address }}<span v-if="restaurant.city">, {{ restaurant.city }}</span><span v-if="restaurant.state">, {{ restaurant.state }}</span><span v-if="restaurant.postal_code"> {{ restaurant.postal_code }}</span>
                            </span>
                        </div>

                        <a
                            v-if="restaurant.lat && restaurant.lng"
                            :href="`https://www.google.com/maps/dir/?api=1&destination=${restaurant.lat},${restaurant.lng}`"
                            target="_blank"
                            rel="noopener"
                            class="flex items-center gap-2.5 text-sm text-muted-foreground hover:text-primary transition-colors"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
                            Get directions
                        </a>

                        <button
                            v-if="restaurant.phone"
                            class="flex w-full items-center gap-2.5 text-sm text-muted-foreground hover:text-primary transition-colors"
                            @click="callPhone(restaurant.phone)"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                            {{ restaurant.phone }}
                        </button>

                        <button
                            v-if="restaurant.website_url"
                            class="flex w-full items-center gap-2.5 text-sm text-muted-foreground hover:text-primary transition-colors"
                            @click="openWebsite(restaurant.website_url)"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
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
