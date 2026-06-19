<script setup lang="ts">
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import StarRating from '@/Components/StarRating.vue';
import ScoreBreakdown from '@/Components/ScoreBreakdown.vue';
import ResultMap from '@/Components/ResultMap.vue';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    restaurant: {
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
    };
    rank: number;
}>();

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

const sourceColor = computed(() => {
    switch (props.restaurant.source) {
        case 'yelp': return 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400';
        case 'foursquare': return 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400';
        case 'overpass': return 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400';
        case 'bizdata': return 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400';
        default: return 'bg-muted text-muted-foreground';
    }
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

const animation = computed(() => ({
    initial: { opacity: 0, y: 24, scale: 0.96 },
    enter: {
        opacity: 1,
        y: 0,
        scale: 1,
        transition: {
            delay: (props.rank - 1) * 70,
            duration: 450,
            ease: [0.16, 1, 0.3, 1],
        },
    },
}));

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
            class="group relative overflow-hidden border border-border/60 bg-card transition-all duration-300 hover:-translate-y-1 hover:border-primary/30 hover:shadow-xl dark:hover:shadow-primary/5"
            :class="isTop3 ? `hover:shadow-2xl ${rankStyle.ring}` : ''"
        >
            <!-- Subtle gradient glow for top 3 -->
            <div
                v-if="isTop3"
                class="pointer-events-none absolute inset-0 opacity-0 transition-opacity duration-500 group-hover:opacity-100"
                :class="rank === 1 ? 'bg-gradient-to-br from-amber-500/5 via-transparent to-transparent' : rank === 2 ? 'bg-gradient-to-br from-slate-400/5 via-transparent to-transparent' : 'bg-gradient-to-br from-orange-500/5 via-transparent to-transparent'"
            />

            <!-- Photo section -->
            <div class="relative h-44 w-full overflow-hidden sm:h-52">
                <div
                    v-if="restaurant.photo_url"
                    class="absolute inset-0 bg-cover bg-center transition-transform duration-500 group-hover:scale-110"
                    :style="{ backgroundImage: `url(${restaurant.photo_url})` }"
                />
                <div
                    v-else
                    class="flex h-full w-full items-center justify-center bg-gradient-to-br from-muted to-muted/50"
                >
                    <span class="text-5xl opacity-40">🍽️</span>
                </div>

                <!-- Dark gradient overlay at bottom for readability -->
                <div class="pointer-events-none absolute inset-x-0 bottom-0 h-24 bg-gradient-to-t from-black/50 to-transparent" />

                <!-- Rank badge -->
                <div class="absolute left-3 top-3">
                    <div
                        class="flex h-9 min-w-[36px] items-center justify-center rounded-full bg-gradient-to-r px-3 text-sm font-bold shadow-lg ring-2 ring-white/50 backdrop-blur-sm"
                        :class="[rankStyle.bg, rankStyle.text]"
                    >
                        <span v-if="rank === 1">🔥</span>
                        <span v-else class="tabular-nums">#{{ rankLabel }}</span>
                    </div>
                </div>

                <!-- Award badge -->
                <div v-if="restaurant.has_award" class="absolute right-3 top-3">
                    <div class="flex h-9 w-9 items-center justify-center rounded-full bg-amber-400/90 shadow-lg ring-2 ring-white/50 backdrop-blur-sm">
                        <span class="text-sm">⭐</span>
                    </div>
                </div>

                <!-- Source badge -->
                <div class="absolute bottom-3 left-3">
                    <span
                        class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wider shadow-sm backdrop-blur-sm"
                        :class="sourceColor"
                    >
                        {{ restaurant.source || 'ipop360' }}
                    </span>
                </div>

                <!-- Map thumbnail bottom-right of photo -->
                <div class="absolute bottom-3 right-3 h-14 w-20 overflow-hidden rounded-lg border-2 border-white/50 shadow-lg">
                    <ResultMap
                        v-if="mapCoords"
                        :lat="mapCoords.lat"
                        :lng="mapCoords.lng"
                        :name="restaurant.name"
                    />
                    <div v-else class="flex h-full w-full items-center justify-center bg-muted text-[10px] text-muted-foreground">
                        No map
                    </div>
                </div>
            </div>

            <!-- Content section -->
            <div class="space-y-2.5 p-4">
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
                <p v-if="restaurant.description" class="line-clamp-2 text-xs leading-relaxed text-muted-foreground">
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

                <!-- Quick actions -->
                <div v-if="restaurant.phone || restaurant.website_url" class="flex items-center gap-3 pt-0.5">
                    <button
                        v-if="restaurant.phone"
                        class="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-primary transition-colors"
                        :title="`Call ${restaurant.phone}`"
                        @click.prevent="callPhone(restaurant.phone)"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                        Call
                    </button>
                    <button
                        v-if="restaurant.website_url"
                        class="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-primary transition-colors"
                        title="Visit website"
                        @click.prevent="openWebsite(restaurant.website_url)"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                        Website
                    </button>
                    <a
                        v-if="mapCoords && restaurant.id > 0"
                        :href="`https://www.google.com/maps/dir/?api=1&destination=${mapCoords.lat},${mapCoords.lng}`"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-primary transition-colors"
                        title="Get directions"
                        @click.prevent
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18 3 12l6-6"/><path d="M15 6l6 6-6 6"/></svg>
                        Directions
                    </a>
                </div>

                <!-- Score breakdown -->
                <div v-if="restaurant.score_breakdown" class="pt-1">
                    <ScoreBreakdown :breakdown="restaurant.score_breakdown" />
                </div>
            </div>
        </Card>
    </Component>
</template>
