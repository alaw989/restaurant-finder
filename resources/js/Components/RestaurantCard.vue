<script setup lang="ts">
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import StarRating from '@/Components/StarRating.vue';
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

const isTop3 = computed(() => props.rank <= 3);

const rankStyle = computed(() => {
    if (props.rank === 1) return { bg: 'from-amber-400 to-yellow-500', text: 'text-white', ring: 'shadow-amber-500/30' };
    if (props.rank === 2) return { bg: 'from-slate-300 to-slate-400', text: 'text-white', ring: 'shadow-slate-400/30' };
    if (props.rank === 3) return { bg: 'from-orange-400 to-amber-600', text: 'text-white', ring: 'shadow-orange-500/30' };
    return { bg: 'from-muted to-muted-foreground/20', text: 'text-muted-foreground', ring: '' };
});

const rankLabel = computed(() => {
    if (props.rank === 1) return '#1 🔥';
    if (props.rank <= 3) return `#${props.rank} Hot`;
    if (props.rank <= 10) return `#${props.rank} Trending`;
    return `#${props.rank}`;
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
            ease: [0.16, 1, 0.3, 1], // cubic-bezier for snappy "electric" feel
        },
    },
}));

function mapsUrl(name: string, city: string | null): string {
    const q = city ? `${name}, ${city}` : name;
    return `https://www.google.com/maps/search/${encodeURIComponent(q)}`;
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
            class="group relative overflow-hidden border border-border/60 bg-card/80 backdrop-blur-xl transition-all duration-300 hover:-translate-y-1 hover:border-primary/30 hover:shadow-xl dark:hover:shadow-primary/5"
            :class="isTop3 ? `hover:shadow-2xl ${rankStyle.ring}` : ''"
        >
            <!-- Subtle gradient glow for top 3 -->
            <div
                v-if="isTop3"
                class="pointer-events-none absolute inset-0 opacity-0 transition-opacity duration-500 group-hover:opacity-100"
                :class="rank === 1 ? 'bg-gradient-to-br from-amber-500/5 via-transparent to-transparent' : rank === 2 ? 'bg-gradient-to-br from-slate-400/5 via-transparent to-transparent' : 'bg-gradient-to-br from-orange-500/5 via-transparent to-transparent'"
            />

            <div class="relative flex flex-col sm:flex-row">
                <!-- Thumbnail -->
                <div class="relative h-40 w-full shrink-0 overflow-hidden sm:h-auto sm:w-44">
                    <div
                        v-if="restaurant.photo_url"
                        class="absolute inset-0 bg-cover bg-center transition-transform duration-500 group-hover:scale-110"
                        :style="{ backgroundImage: `url(${restaurant.photo_url})` }"
                    />
                    <div
                        v-else
                        class="flex h-full w-full items-center justify-center bg-gradient-to-br from-muted to-muted/50"
                    >
                        <span class="text-4xl opacity-40">🍽️</span>
                    </div>
                    <!-- Rank badge overlay -->
                    <div class="absolute left-2 top-2">
                        <div
                            class="flex h-8 items-center rounded-full bg-gradient-to-r px-3 text-xs font-bold shadow-lg"
                            :class="[rankStyle.bg, rankStyle.text]"
                        >
                            {{ rankLabel }}
                        </div>
                    </div>
                </div>

                <!-- Content -->
                <CardContent class="relative flex flex-1 flex-col gap-2.5 p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <h3 class="truncate text-base font-semibold text-foreground transition-colors group-hover:text-primary">
                                {{ restaurant.name }}
                                <span v-if="restaurant.id < 0" class="ml-1 text-xs text-muted-foreground">↗</span>
                            </h3>
                            <p v-if="restaurant.address" class="truncate text-xs text-muted-foreground">
                                {{ restaurant.address }}
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <StarRating
                            v-if="restaurant.yelp_rating"
                            :rating="restaurant.yelp_rating"
                            size="sm"
                        />
                        <StarRating
                            v-else-if="restaurant.google_rating"
                            :rating="restaurant.google_rating"
                            size="sm"
                        />
                        <span v-if="restaurant.yelp_review_count" class="text-xs text-muted-foreground">
                            {{ restaurant.yelp_review_count }} reviews
                        </span>
                        <span v-else-if="restaurant.google_review_count" class="text-xs text-muted-foreground">
                            {{ restaurant.google_review_count }} reviews
                        </span>
                        <span v-if="restaurant.price_range" class="text-sm font-semibold text-emerald-500 dark:text-emerald-400">
                            {{ restaurant.price_range }}
                        </span>
                        <span v-if="restaurant.distance != null" class="text-xs text-muted-foreground">
                            {{ Number(restaurant.distance).toFixed(1) }} km
                        </span>
                    </div>

                    <div class="flex flex-wrap gap-1.5 pt-0.5">
                        <Badge
                            v-for="cuisine in restaurant.cuisines"
                            :key="cuisine.id"
                            variant="secondary"
                            class="bg-primary/5 text-xs font-medium text-primary/70 hover:bg-primary/10"
                        >
                            {{ cuisine.name }}
                        </Badge>
                    </div>
                </CardContent>
            </div>
        </Card>
    </Component>
</template>
