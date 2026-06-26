<script setup lang="ts">
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import StarRating from '@/Components/StarRating.vue';
import CardGallery from '@/Components/CardGallery.vue';
import ScoreChip from '@/Components/ScoreChip.vue';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { Restaurant } from '@/types/restaurant';
import { cuisineGradient, FOOD_FALLBACK_GRADIENT } from '@/lib/cuisine';
import { Phone, Globe, Navigation, Heart } from '@lucide/vue';
import { useFavorites } from '@/composables/useFavorites';

const props = defineProps<{
    restaurant: Restaurant;
    rank: number;
}>();

const { isFavorited, toggle } = useFavorites();

const isTop3 = computed(() => props.rank <= 3);

const rankStyle = computed(() => {
    if (props.rank === 1) return { bg: 'from-amber-400 to-yellow-500', text: 'text-white', ring: 'shadow-amber-500/30' };
    if (props.rank === 2) return { bg: 'from-slate-300 to-slate-400', text: 'text-slate-900', ring: 'shadow-slate-400/30' };
    if (props.rank === 3) return { bg: 'from-orange-400 to-amber-600', text: 'text-white', ring: 'shadow-orange-500/30' };
    return { bg: 'from-muted to-muted-foreground/20', text: 'text-muted-foreground', ring: '' };
});

const rankLabel = computed(() => {
    if (props.rank === 1) return '1';
    return String(props.rank);
});

// Normalize photos: hero first, unique, capped at 6
const photos = computed(() => {
    const unique = Array.from(new Set([props.restaurant.photo_url, ...(props.restaurant.photos ?? [])].filter(Boolean))) as string[];
    return unique.slice(0, 6);
});

// Gradient for CardGallery backdrop (falls back to food gradient if no cuisines)
const gradient = computed(() => {
    const primaryCuisine = props.restaurant.cuisines[0]?.slug;
    return primaryCuisine ? cuisineGradient(primaryCuisine) : FOOD_FALLBACK_GRADIENT;
});

const displayRating = computed(() => {
    if (props.restaurant.yelp_rating) return { rating: props.restaurant.yelp_rating, count: props.restaurant.yelp_review_count, source: 'Yelp' };
    if (props.restaurant.google_rating) return { rating: props.restaurant.google_rating, count: props.restaurant.google_review_count, source: 'Google' };
    return null;
});

const mapCoords = computed(() => {
    if (props.restaurant.lat != null && props.restaurant.lng != null) {
        return { lat: props.restaurant.lat, lng: props.restaurant.lng };
    }
    return null;
});

// Entrance animation: fade + rise, capped stagger at rank 12
const animation = computed(() => {
    const cappedRank = Math.min(Math.max(props.rank - 1, 0), 11);
    return {
        initial: { opacity: 0, y: 16 },
        enter: {
            opacity: 1,
            y: 0,
            transition: {
                delay: cappedRank * 45,
                duration: 320,
                ease: [0.16, 1, 0.3, 1],
            },
        },
    };
});

function mapsUrl(name: string, city: string | null): string {
    const q = city ? `${name}, ${city}` : name;
    return `https://www.google.com/maps/search/${encodeURIComponent(q)}`;
}

function callPhone(phone: string) {
    window.location.href = `tel:${phone}`;
}

function openWebsite(url: string) {
    if (!url.startsWith('http')) url = 'https://' + url;
    window.open(url, '_blank');
}

const saved = computed(() => isFavorited(props.restaurant));

const ariaLabel = computed(() => (saved.value ? 'Saved' : 'Save restaurant'));
</script>

<template>
    <Component
        :is="restaurant.id > 0 ? Link : 'a'"
        v-bind="restaurant.id > 0
            ? { href: `/restaurants/${restaurant.slug}` }
            : { href: mapsUrl(restaurant.name, restaurant.city), target: '_blank', rel: 'noopener' }
        "
        v-motion="animation"
    >
        <Card
            class="group relative overflow-hidden rounded-2xl transition-[transform,box-shadow,border-color] duration-300 ease-out hover:-translate-y-1 hover:border-primary/30 hover:shadow-xl"
        >
            <!-- Photo section with CardGallery -->
            <CardGallery
                :photos="photos"
                :gradient="gradient"
                :alt="restaurant.name"
                aspect="4/3"
            >
                <template #overlays>
                    <!-- Rank badge -->
                    <div class="absolute left-3 top-3">
                        <div
                            class="flex h-9 min-w-[36px] items-center justify-center rounded-full bg-gradient-to-r px-3 text-sm font-bold shadow-lg ring-2 ring-white/50 backdrop-blur-sm transition-transform duration-200 group-hover:scale-110"
                            :class="[rankStyle.bg, rankStyle.text]"
                        >
                            <span v-if="rank === 1">🔥</span>
                            <span v-else class="tabular-nums">#{{ rankLabel }}</span>
                        </div>
                    </div>

                    <!-- Award pill -->
                    <div v-if="restaurant.has_award" class="absolute bottom-3 left-3">
                        <div class="inline-flex items-center gap-1 rounded-full bg-amber-400/90 px-2.5 py-1 text-xs font-semibold text-white shadow-lg ring-2 ring-white/50 backdrop-blur-sm">
                            <span>⭐</span>
                            <span>Michelin</span>
                        </div>
                    </div>

                    <!-- ScoreChip -->
                    <div v-if="restaurant.popularity_score != null" class="absolute bottom-3 right-3">
                        <ScoreChip :total="restaurant.popularity_score" />
                    </div>

                    <!-- Heart (persistent, hybrid: localStorage for guests, server for authed) -->
                    <button
                        class="absolute right-3 top-3 flex h-8 w-8 items-center justify-center rounded-full bg-white/85 text-foreground shadow-md ring-2 ring-white/50 transition-all hover:bg-white hover:scale-110 group-hover:opacity-100 opacity-0 backdrop-blur-sm"
                        :class="{ 'text-red-500 fill-red-500': saved, 'opacity-100': saved }"
                        :aria-label="ariaLabel"
                        @click.stop="() => toggle(restaurant)"
                    >
                        <Heart
                            class="h-4 w-4"
                            :class="saved ? 'fill-current' : 'fill-none stroke-current'"
                        />
                    </button>
                </template>
            </CardGallery>

            <!-- Content section -->
            <div class="p-4 space-y-2">
                <!-- Name + address -->
                <div class="min-w-0">
                    <h3 class="text-base font-semibold text-foreground transition-colors group-hover:text-primary">
                        {{ restaurant.name }}
                    </h3>
                    <p v-if="restaurant.address || restaurant.city" class="truncate text-xs text-muted-foreground">
                        {{ [restaurant.address, restaurant.city, restaurant.state].filter(Boolean).join(', ') }}
                    </p>
                </div>

                <!-- Rating + reviews + price + distance -->
                <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                    <StarRating
                        v-if="displayRating"
                        :rating="displayRating.rating"
                        size="sm"
                    />
                    <span v-if="displayRating" class="text-xs tabular-nums text-muted-foreground">
                        {{ displayRating.count.toLocaleString() }} reviews
                    </span>
                    <span v-if="restaurant.price_range" class="text-sm font-semibold text-emerald-500 dark:text-emerald-400">
                        {{ restaurant.price_range }}
                    </span>
                    <span v-if="restaurant.distance != null" class="text-xs text-muted-foreground">
                        {{ Number(restaurant.distance).toFixed(1) }} km
                    </span>
                </div>

                <!-- Description -->
                <p v-if="restaurant.description" class="line-clamp-1 sm:line-clamp-2 text-xs leading-relaxed text-muted-foreground">
                    {{ restaurant.description }}
                </p>

                <!-- Cuisine badges -->
                <div v-if="restaurant.cuisines.length > 0" class="flex flex-wrap gap-1">
                    <Badge
                        v-for="cuisine in restaurant.cuisines"
                        :key="cuisine.id"
                        variant="secondary"
                        class="bg-primary/5 text-[11px] font-medium text-primary/70 hover:bg-primary/10"
                    >
                        {{ cuisine.name }}
                    </Badge>
                </div>

                <!-- Action icon pills -->
                <div class="flex items-center gap-2 pt-0.5">
                    <a
                        v-if="mapCoords"
                        :href="`https://www.google.com/maps/dir/?api=1&destination=${mapCoords.lat},${mapCoords.lng}`"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex h-8 items-center gap-1.5 rounded-full bg-muted/50 px-3 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                        title="Get directions"
                        @click.stop
                    >
                        <Navigation class="h-3.5 w-3.5" />
                        <span>Directions</span>
                    </a>
                    <button
                        v-if="restaurant.phone"
                        class="inline-flex h-8 items-center gap-1.5 rounded-full bg-muted/50 px-3 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                        :title="`Call ${restaurant.phone}`"
                        @click.stop="callPhone(restaurant.phone)"
                    >
                        <Phone class="h-3.5 w-3.5" />
                        <span>Call</span>
                    </button>
                    <button
                        v-if="restaurant.website_url"
                        class="inline-flex h-8 items-center gap-1.5 rounded-full bg-muted/50 px-3 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                        title="Visit website"
                        @click.stop="openWebsite(restaurant.website_url)"
                    >
                        <Globe class="h-3.5 w-3.5" />
                        <span>Website</span>
                    </button>
                </div>
            </div>
        </Card>
    </Component>
</template>
