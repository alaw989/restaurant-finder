<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { ref, onMounted, computed } from 'vue'
import CuisinePicker from '@/Components/CuisinePicker.vue'
import LocationPicker from '@/Components/LocationPicker.vue'
import RestaurantCard from '@/Components/RestaurantCard.vue'
import RestaurantCardSkeleton from '@/Components/RestaurantCardSkeleton.vue'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent } from '@/components/ui/card'
import { Search, MapPin, Utensils, X } from '@lucide/vue'
import type { Restaurant } from '@/types/restaurant'

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

const selectedCategory = ref('')
const selectedCuisine = ref<string | undefined>()
const selectedLabel = ref<string | null>(null)
const location = ref<Location>(props.location ?? { city: null, state: null })
const lat = ref<number | null>(props.fallbackCoords?.lat ?? null)
const lng = ref<number | null>(props.fallbackCoords?.lng ?? null)
const sort = ref<string>('best_match')

const sortOptions = [
    { value: 'best_match', label: 'Best Match' },
    { value: 'nearest', label: 'Nearest' },
    { value: 'rating', label: 'Rating' },
    { value: 'reviews', label: 'Reviews' },
    { value: 'price', label: 'Price' },
]

const restaurants = ref<Restaurant[]>([])
const phase = ref<Phase>('idle')
const nextPageUrl = ref<string | null>(null)
const detectingLocation = ref(false)
const searchError = ref<string | null>(null)
const loadMoreError = ref<string | null>(null)
const geolocationError = ref<string | null>(null)

const resultCount = computed(() => restaurants.value.length)
const isResultsPhase = computed(() => phase.value !== 'idle')
const hasResultsOrError = computed(() => phase.value === 'results' || phase.value === 'error')

onMounted(() => {
    // Check if we already have location from prior session
    const savedLocation = localStorage.getItem('foodrank_location')
    if (savedLocation) {
        try {
            const parsed = JSON.parse(savedLocation)
            location.value = parsed
            if (parsed.city) return // Don't re-prompt if user already has a saved location
        } catch {}
    }

    // Auto-detect via GPS — always try on first visit, overrides IP-based guess
    if (navigator.geolocation) {
        detectingLocation.value = true
        navigator.geolocation.getCurrentPosition(
            async (position) => {
                lat.value = position.coords.latitude
                lng.value = position.coords.longitude

                try {
                    const res = await fetch(
                        `/api/geocode?lat=${lat.value}&lng=${lng.value}`
                    )
                    const data = await res.json()
                    if (data.city || data.state) {
                        location.value = {
                            city: data.city ?? null,
                            state: data.state ?? null,
                        }
                        localStorage.setItem('foodrank_location', JSON.stringify(location.value))
                    }
                } catch {
                    // Keep IP-based fallback
                }
                detectingLocation.value = false
            },
            () => {
                detectingLocation.value = false
                geolocationError.value = 'Unable to detect your location. Please enter it manually.'
            },
            { timeout: 10000, enableHighAccuracy: false }
        )
    }
})

function detectLocation() {
    if (!navigator.geolocation) return
    detectingLocation.value = true
    navigator.geolocation.getCurrentPosition(
        async (position) => {
            lat.value = position.coords.latitude
            lng.value = position.coords.longitude
            try {
                const res = await fetch(`/api/geocode?lat=${lat.value}&lng=${lng.value}`)
                const data = await res.json()
                if (data.city || data.state) {
                    location.value = { city: data.city ?? null, state: data.state ?? null }
                    localStorage.setItem('foodrank_location', JSON.stringify(location.value))
                }
            } catch {
                // Keep existing coordinates
            }
            detectingLocation.value = false
        },
        () => {
            detectingLocation.value = false
            geolocationError.value = 'Unable to detect your location. Please enter it manually.'
        },
        { timeout: 10000, enableHighAccuracy: false }
    )
}

function onCuisineSelect(payload: { category: string; cuisine?: string; label: string }) {
    selectedCategory.value = payload.category
    selectedCuisine.value = payload.cuisine
    selectedLabel.value = payload.label
}

async function onLocationUpdate(newLocation: Location) {
    location.value = newLocation
    localStorage.setItem('foodrank_location', JSON.stringify(newLocation))

    if (newLocation.city) {
        try {
            const params = new URLSearchParams({ city: newLocation.city })
            if (newLocation.state) params.set('state', newLocation.state)
            const res = await fetch(`/api/geocode/forward?${params}`)
            const data = await res.json()
            if (data.lat != null && data.lng != null) {
                lat.value = data.lat
                lng.value = data.lng
            }
        } catch {
            // Keep existing coordinates
        }
    }
}

async function search() {
    phase.value = 'searching'
    searchError.value = null
    loadMoreError.value = null
    geolocationError.value = null // Dismiss geolocation banner on search

    const params = new URLSearchParams()
    if (selectedCuisine.value) {
        params.set('cuisine', selectedCuisine.value)
    } else if (selectedCategory.value) {
        params.set('category', selectedCategory.value)
    }
    if (lat.value !== null) params.set('lat', lat.value.toString())
    if (lng.value !== null) params.set('lng', lng.value.toString())
    params.set('sort', sort.value)

    try {
        const response = await fetch(`/api/restaurants?${params}`)
        if (!response.ok) {
            throw new Error('Search failed')
        }
        const data = await response.json()
        restaurants.value = data.data ?? []
        nextPageUrl.value = data.next_page_url

        if (restaurants.value.length === 0) {
            phase.value = 'empty'
        } else {
            phase.value = 'results'
        }
        searchError.value = null
    } catch {
        searchError.value = 'Couldn\'t reach the listing service. Please try again.'
        restaurants.value = []
        nextPageUrl.value = null
        phase.value = 'error'
    }
}

async function loadMore() {
    if (!nextPageUrl.value || phase.value !== 'results') return

    try {
        const response = await fetch(nextPageUrl.value)
        if (!response.ok) {
            throw new Error('Load more failed')
        }
        const data = await response.json()
        restaurants.value.push(...(data.data ?? []))
        nextPageUrl.value = data.next_page_url
        loadMoreError.value = null
    } catch {
        loadMoreError.value = 'Couldn\'t load more results. Please try again.'
    }
}

function resetToIdle() {
    phase.value = 'idle'
    restaurants.value = []
    nextPageUrl.value = null
    searchError.value = null
    loadMoreError.value = null
}

function refineSearch() {
    phase.value = 'idle'
    geolocationError.value = null
}
</script>

<template>
    <div class="flex min-h-screen flex-col bg-background">
        <Head title="Find Popular Restaurants Near You" />

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
                    <Button variant="ghost" size="sm" @click="geolocationError = null">
                        <X class="h-4 w-4" />
                    </Button>
                </CardContent>
            </Card>
        </Transition>

        <!-- Sticky compact search bar (visible in results phases) -->
        <Transition name="bar-in">
            <div
                v-if="isResultsPhase"
                class="sticky top-0 z-20 border-b bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/60"
            >
                <div class="mx-auto flex max-w-7xl items-center gap-3 px-4 py-3 sm:gap-4">
                    <!-- Logo mark -->
                    <Link href="/" @click="resetToIdle" class="flex items-center gap-1.5 text-lg font-semibold">
                        <span class="text-xl">🍽️</span>
                        <span class="hidden sm:inline">iPop360</span>
                    </Link>

                    <!-- Compact pickers -->
                    <div class="flex flex-1 items-center gap-2 overflow-x-auto">
                        <CuisinePicker
                            :categories="categories"
                            :compact="true"
                            @select="onCuisineSelect"
                        />
                        <div class="flex items-center gap-1 text-sm text-muted-foreground">
                            <MapPin class="h-3.5 w-3.5" />
                            <span>{{ location.city || location.state || 'Everywhere' }}</span>
                        </div>
                    </div>

                    <!-- Favorites link (authed users) -->
                    <Link
                        v-if="$page.props.auth?.user"
                        href="/favorites"
                        class="flex items-center gap-1.5 text-sm font-medium text-muted-foreground hover:text-primary transition-colors"
                    >
                        Favorites
                    </Link>

                    <!-- Search icon -->
                    <Button size="icon" variant="ghost" aria-label="Refine search" @click="refineSearch">
                        <Search class="h-5 w-5" />
                    </Button>
                </div>
            </div>
        </Transition>

        <!-- Main content area -->
        <div class="flex flex-1 flex-col">
            <!-- Centered hero (idle phase) -->
            <Transition name="hero-out">
                <div v-if="phase === 'idle'" class="flex flex-1 flex-col items-center justify-center px-4">
                    <div class="w-full max-w-4xl text-center">
                        <!-- Logo -->
                        <Link href="/" class="mb-8 inline-flex items-center gap-2 text-3xl font-bold tracking-tight text-foreground">
                            <span class="text-4xl">🍽️</span>
                            iPop360
                        </Link>

                        <!-- Dynamic sentence -->
                        <div class="mt-8 flex flex-wrap items-center justify-center gap-x-2 text-3xl font-medium leading-relaxed sm:text-4xl">
                            <span>Find the most Popular</span>
                            <CuisinePicker :categories="categories" @select="onCuisineSelect" />
                            <span>Restaurants in</span>
                            <LocationPicker :location="location" :detecting="detectingLocation" @update="onLocationUpdate" @coords="(lt, lg) => { lat = lt; lng = lg }" @detect="detectLocation" />
                        </div>

                        <!-- Search button -->
                        <div class="mt-8">
                            <Button
                                size="lg"
                                :disabled="detectingLocation"
                                @click="search"
                                class="relative px-8 transition-all hover:scale-105 active:scale-95"
                            >
                                <span v-if="detectingLocation" class="inline-flex items-center gap-2">
                                    <span class="inline-block h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
                                    Detecting location...
                                </span>
                                <span v-else>Search</span>
                            </Button>
                        </div>
                    </div>
                </div>
            </Transition>

            <!-- Results area (all non-idle phases) -->
            <Transition name="results-in">
                <div v-if="isResultsPhase" class="mx-auto w-full px-4 pb-8 pt-6">
                    <!-- Max width only when in results phase -->
                    <div class="mx-auto max-w-7xl">
                        <!-- Inner state swap with fade transition -->
                        <Transition name="fade" mode="out-in">
                            <!-- Skeleton grid (searching phase) -->
                            <div v-if="phase === 'searching'" key="skeleton" class="grid grid-cols-1 gap-x-5 gap-y-7 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                                <RestaurantCardSkeleton v-for="i in 8" :key="`skeleton-${i}`" />
                            </div>

                            <!-- Error state -->
                            <Card v-else-if="phase === 'error'" key="error" class="border-destructive bg-destructive/10">
                                <CardContent class="flex flex-col items-center gap-4 py-8 text-center">
                                    <div class="flex items-center gap-2">
                                        <Badge variant="destructive">Search Error</Badge>
                                        <span class="text-muted-foreground">{{ searchError }}</span>
                                    </div>
                                    <div class="flex gap-2">
                                        <Button variant="outline" @click="resetToIdle">
                                            Start Over
                                        </Button>
                                        <Button @click="search">
                                            <Search class="mr-2 h-4 w-4" />
                                            Try Again
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>

                            <!-- Empty state -->
                            <div v-else-if="phase === 'empty'" key="empty" class="py-16 text-center">
                                <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-muted">
                                    <Utensils class="h-8 w-8 text-muted-foreground" />
                                </div>
                                <h3 class="text-lg font-semibold">No restaurants found</h3>
                                <p class="mt-2 text-sm text-muted-foreground">Try a different cuisine or location.</p>
                                <Button variant="outline" class="mt-4" @click="resetToIdle">
                                    Start Over
                                </Button>
                            </div>

                            <!-- Results grid -->
                            <div v-else key="results">
                                <!-- Sort & count bar -->
                                <div class="mb-4 flex flex-wrap items-center justify-between gap-2 sm:gap-4">
                                    <div class="text-sm text-muted-foreground">
                                        {{ resultCount }} result{{ resultCount !== 1 ? 's' : '' }}
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <label for="sort-select" class="text-sm text-muted-foreground">Sort:</label>
                                        <select
                                            id="sort-select"
                                            v-model="sort"
                                            @change="search()"
                                            class="rounded-md border border-input bg-background px-3 py-1.5 text-sm ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                                        >
                                            <option v-for="option in sortOptions" :key="option.value" :value="option.value">
                                                {{ option.label }}
                                            </option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Card grid -->
                                <div class="grid grid-cols-1 gap-x-5 gap-y-7 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                                    <RestaurantCard
                                        v-for="(restaurant, index) in restaurants"
                                        :key="restaurant.id"
                                        :restaurant="restaurant"
                                        :rank="index + 1"
                                    />
                                </div>

                                <!-- Load more -->
                                <div v-if="nextPageUrl || loadMoreError" class="mt-8 flex flex-col items-center gap-3">
                                    <Button
                                        v-if="nextPageUrl"
                                        variant="outline"
                                        @click="loadMore"
                                        class="rounded-full px-8"
                                    >
                                        Load More
                                    </Button>
                                    <Card v-if="loadMoreError" class="border-destructive bg-destructive/10">
                                        <CardContent class="flex items-center gap-3 py-3">
                                            <Badge variant="destructive" class="text-xs">Load Error</Badge>
                                            <span class="text-sm text-muted-foreground">{{ loadMoreError }}</span>
                                            <div class="ml-auto flex gap-2">
                                                <Button variant="ghost" size="sm" @click="loadMoreError = null">
                                                    <X class="h-4 w-4" />
                                                </Button>
                                                <Button size="sm" @click="loadMore">
                                                    Retry
                                                </Button>
                                            </div>
                                        </CardContent>
                                    </Card>
                                </div>
                            </div>
                        </Transition>
                    </div>
                </div>
            </Transition>
        </div>
    </div>
</template>
