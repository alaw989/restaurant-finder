<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { ref, computed, onMounted } from 'vue'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { X } from '@lucide/vue'
import JsonLd from '@/Components/JsonLd.vue'
import HeroSearch from '@/Components/HeroSearch.vue'
import StickySearchBar from '@/Components/StickySearchBar.vue'
import ResultsGrid from '@/Components/ResultsGrid.vue'

import { useSeo, generateWebSiteJsonLd, generateOrganizationJsonLd } from '@/composables/useSeo'
import { useRestaurantSearch } from '@/composables/useRestaurantSearch'
import { useGeolocation } from '@/composables/useGeolocation'
import { usePersistedLocation } from '@/composables/usePersistedLocation'

type Phase = 'idle' | 'searching' | 'results' | 'empty' | 'error'

interface Cuisine {
    id: number
    name: string
    slug: string
    icon: string | null
}

interface Category {
    id: number
    name: string
    slug: string
    icon: string | null
    cuisines: Cuisine[]
}

interface Location {
    city: string | null
    state: string | null
}

const props = defineProps<{
    categories: Category[]
    location: Location | null
    fallbackCoords: { lat: number; lng: number } | null
}>()

// Phase machine
const phase = ref<Phase>('idle')
function setPhase(newPhase: Phase) {
    phase.value = newPhase
}
function getPhase(): Phase {
    return phase.value
}
const isResultsPhase = computed(() => phase.value !== 'idle')

// Cuisine selection state
const selectedCategory = ref('')
const selectedCuisine = ref<string | undefined>()
const selectedLabel = ref<string | null>(null)

// Sort state
const sort = ref<string>('best_match')
const sortOptions = [
    { value: 'best_match', label: 'Best Match' },
    { value: 'nearest', label: 'Nearest' },
    { value: 'rating', label: 'Rating' },
    { value: 'reviews', label: 'Reviews' },
    { value: 'price', label: 'Price' },
]

// Persisted location (city/state/coords from localStorage)
const { location: persistedLocation, lat, lng, persistLocation, restore: restorePersistedLocation } = usePersistedLocation(props.location, props.fallbackCoords)

// Geolocation (GPS + reverse geocode)
const { detectingLocation, geolocationError, detectLocation } = useGeolocation(persistLocation)

// Restaurant search (search/resort/loadMore)
const {
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
} = useRestaurantSearch(setPhase, getPhase)

// Result count for display
const resultCount = computed(() => restaurants.value.length)

// SEO
const baseUrl = computed(() => {
    if (typeof window !== 'undefined') {
        return `${window.location.protocol}//${window.location.host}`
    }
    return 'https://ipop360.vp-associates.com'
})

const seoData = computed(() => {
    return useSeo({
        title: 'Find Popular Restaurants Near You | iPop360',
        description: 'Discover top-rated restaurants near you with iPop360. Real reviews, accurate ratings, and smart rankings help you find the best dining options in your area.',
        url: `${baseUrl.value}/`,
        type: 'website',
    })
})

const structuredData = computed(() => {
    const webSite = generateWebSiteJsonLd(`${baseUrl.value}/`, 'iPop360')
    const organization = generateOrganizationJsonLd(`${baseUrl.value}/`, 'iPop360')
    return [webSite, organization]
})

// Event handlers from child components
function onCuisineSelect(payload: { category: string; cuisine?: string; label: string }) {
    selectedCategory.value = payload.category
    selectedCuisine.value = payload.cuisine
    selectedLabel.value = payload.label
}

function onLocationUpdate(newLocation: Location) {
    persistedLocation.value = newLocation
}

function onCoords(lt: number, lg: number) {
    lat.value = lt
    lng.value = lg
    persistLocation(persistedLocation.value.city, persistedLocation.value.state, lt, lg)
}

function onSearch() {
    search({
        selectedCuisine: selectedCuisine.value,
        selectedCategory: selectedCategory.value,
        lat,
        lng,
        sort,
    })
}

function onResort() {
    resort({
        selectedCuisine: selectedCuisine.value,
        selectedCategory: selectedCategory.value,
        lat,
        lng,
        sort,
    })
}

function onLoadMore() {
    loadMore()
}

function resetToIdle() {
    setPhase('idle')
    // Fresh slate: clear the cuisine selection so the remounted CuisinePicker's
    // "any cuisine" label is honest (it owns its own selectedLabel, which resets
    // on remount — clearing the parent stops the old cuisine being silently
    // reused). City/coords/sort are intentionally kept.
    selectedCategory.value = ''
    selectedCuisine.value = undefined
    selectedLabel.value = null
    geolocationError.value = null
    resetState()
}

function refineSearch() {
    setPhase('idle')
    // Fresh slate on back/refine: clear cuisine (same reason as resetToIdle).
    // City/coords/sort are kept so the user can just re-search.
    selectedCategory.value = ''
    selectedCuisine.value = undefined
    selectedLabel.value = null
    geolocationError.value = null
}

function dismissGeolocationError() {
    geolocationError.value = null
}

function dismissLoadMoreError() {
    loadMoreError.value = null
}

// Mount: restore persisted location or auto-detect via GPS
onMounted(() => {
    restorePersistedLocation(() => {
        // No saved location — auto-detect via GPS
        detectLocation()
    })
})
</script>

<template>
    <div class="flex min-h-screen flex-col bg-background">
        <Head>
            <title>{{ seoData.title }}</title>
            <meta name="description" :content="seoData.description" />
            <link rel="canonical" :href="seoData.canonical" />
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

        <!-- Visually-hidden page title for accessibility -->
        <h1 class="sr-only">Find Popular Restaurants Near You</h1>

        <!-- Auth link -->
        <div class="absolute right-4 top-4 z-10 flex items-center gap-2">
            <Link
                v-if="$page.props.auth?.user"
                href="/favorites"
                class="text-sm text-muted-foreground hover:text-primary transition-colors"
            >
                Favorites
            </Link>
            <Link
                v-if="$page.props.auth?.user"
                href="/dashboard"
                class="text-sm text-muted-foreground hover:text-foreground transition-colors"
            >
                Dashboard
            </Link>
            <Button
                v-else
                as="a"
                href="/login"
                variant="outline"
                size="sm"
                class="text-sm"
            >
                Login
            </Button>
        </div>

        <!-- Geolocation error banner -->
        <Transition name="fade">
            <Card v-if="geolocationError && phase === 'idle'" class="absolute left-4 right-4 top-16 z-10 mx-auto max-w-2xl border-destructive bg-destructive/10">
                <CardContent class="flex items-center justify-between py-3">
                    <div class="flex items-center gap-2">
                        <Badge variant="destructive">Location Error</Badge>
                        <span class="text-sm text-destructive">{{ geolocationError }}</span>
                    </div>
                    <Button variant="ghost" size="sm" aria-label="Dismiss" @click="dismissGeolocationError">
                        <X class="h-4 w-4" />
                    </Button>
                </CardContent>
            </Card>
        </Transition>

        <!-- Sticky compact search bar (visible in results phases) -->
        <Transition name="bar-in">
            <StickySearchBar
                v-if="isResultsPhase"
                :location="persistedLocation"
                @refine-search="refineSearch"
            />
        </Transition>

        <!-- Main content area. `relative` anchors the absolute-positioned leaving
             results on the back-transition (results-in-leave-active) to this box,
             not the viewport. -->
        <div class="relative flex flex-1 flex-col">
            <!-- Centered hero (idle phase) -->
            <Transition name="hero-out">
                <HeroSearch
                    v-if="phase === 'idle'"
                    :categories="categories"
                    :location="persistedLocation"
                    :detecting-location="detectingLocation"
                    @cuisine-select="onCuisineSelect"
                    @location-update="onLocationUpdate"
                    @coords="onCoords"
                    @detect="detectLocation"
                    @search="onSearch"
                />
            </Transition>

            <!-- Results area (all non-idle phases) -->
            <Transition name="results-in">
                <ResultsGrid
                    v-if="isResultsPhase"
                    :phase="phase"
                    :restaurants="restaurants"
                    :result-count="resultCount"
                    :sort="sort"
                    :sort-options="sortOptions"
                    :next-page-url="nextPageUrl"
                    :search-error="searchError"
                    :load-more-error="loadMoreError"
                    :lat="lat"
                    :lng="lng"
                    :selected-cuisine="selectedCuisine"
                    :should-stagger="shouldStagger"
                    :is-resorting="isResorting"
                    @update:sort="sort = $event"
                    @resort="onResort"
                    @load-more="onLoadMore"
                    @reset-to-idle="resetToIdle"
                    @dismiss-load-more-error="dismissLoadMoreError"
                    @search="onSearch"
                />
            </Transition>
        </div>

        <!-- Semantic footer -->
        <footer class="border-t border-border bg-muted/40 py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex flex-col items-center justify-between gap-4 sm:flex-row">
                    <div class="text-center sm:text-left">
                        <h3 class="text-lg font-semibold text-foreground">iPop360</h3>
                        <p class="text-sm text-muted-foreground">Discover great restaurants near you.</p>
                    </div>
                    <nav class="flex flex-wrap items-center justify-center gap-4 text-sm sm:justify-end">
                        <a href="/" class="text-muted-foreground hover:text-foreground transition-colors">
                            Home
                        </a>
                        <a href="/restaurants" class="text-muted-foreground hover:text-foreground transition-colors">
                            Browse Restaurants
                        </a>
                        <Link
                            v-if="$page.props.auth?.user"
                            href="/favorites"
                            class="text-muted-foreground hover:text-foreground transition-colors"
                        >
                            Favorites
                        </Link>
                        <Link
                            v-if="$page.props.auth?.user"
                            href="/logout"
                            method="post"
                            class="text-muted-foreground hover:text-foreground transition-colors"
                            as="button"
                        >
                            Logout
                        </Link>
                        <Link
                            v-else
                            href="/login"
                            class="text-muted-foreground hover:text-foreground transition-colors"
                        >
                            Login
                        </Link>
                    </nav>
                </div>
                <div class="mt-6 text-center text-xs text-muted-foreground">
                    <p>&copy; {{ new Date().getFullYear() }} iPop360. All rights reserved.</p>
                </div>
            </div>
        </footer>
    </div>
</template>
