<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { ref, onMounted } from 'vue'
import CuisinePicker from '@/Components/CuisinePicker.vue'
import LocationPicker from '@/Components/LocationPicker.vue'
import RestaurantCard from '@/Components/RestaurantCard.vue'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent } from '@/components/ui/card'

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

interface Restaurant {
    id: number
    name: string
    slug: string
    description: string | null
    address: string | null
    city: string | null
    state: string | null
    lat: number | null
    lng: number | null
    photo_url: string | null
    price_range: string | null
    phone: string | null
    website_url: string | null
    google_rating: number | null
    google_review_count: number
    yelp_rating: number | null
    yelp_review_count: number
    has_award: boolean
    popularity_score: number
    distance: number | null
    cuisines: Array<{ id: number; name: string; slug: string }>
    source: string | null
    score_breakdown: {
        signals: Array<{
            label: string
            weight: number
            normalized: number
            contribution: number
        }>
        total: number
    }
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
    { value: 'price', label: 'Price (Low to High)' },
]

const restaurants = ref<Restaurant[]>([])
const loading = ref(false)
const loadingMore = ref(false)
const searched = ref(false)
const nextPageUrl = ref<string | null>(null)
const detectingLocation = ref(false)
const searchError = ref<string | null>(null)
const loadMoreError = ref<string | null>(null)
const geolocationError = ref<string | null>(null)

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
    loading.value = true
    searched.value = true
    searchError.value = null
    loadMoreError.value = null

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
        searchError.value = null
    } catch {
        searchError.value = 'Couldn\'t reach the listing service. Please try again.'
        restaurants.value = []
        nextPageUrl.value = null
    } finally {
        loading.value = false
    }
}

async function loadMore() {
    if (!nextPageUrl.value || loadingMore.value) return

    loadingMore.value = true
    loadMoreError.value = null
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
    } finally {
        loadingMore.value = false
    }
}
</script>

<template>
    <div class="flex min-h-screen flex-col bg-background">
        <Head title="Find Popular Restaurants Near You" />

        <!-- Auth link -->
        <div class="absolute right-4 top-4 z-10">
            <Link
                v-if="$page.props.auth?.user"
                href="/dashboard"
                class="text-sm text-muted-foreground hover:text-foreground transition-colors"
            >
                Dashboard
            </Link>
            <Link
                v-else
                href="/login"
                class="text-sm text-muted-foreground hover:text-foreground transition-colors"
            >
                Login
            </Link>
        </div>

        <!-- Geolocation error banner -->
        <Card v-if="geolocationError" class="absolute left-4 right-4 top-16 z-10 mx-auto max-w-2xl border-destructive bg-destructive/10">
            <CardContent class="flex items-center justify-between py-3">
                <div class="flex items-center gap-2">
                    <Badge variant="destructive">Location Error</Badge>
                    <span class="text-sm text-destructive">{{ geolocationError }}</span>
                </div>
                <Button variant="ghost" size="sm" @click="geolocationError = null">
                    Dismiss
                </Button>
            </CardContent>
        </Card>

        <!-- Centered content -->
        <div class="flex flex-1 flex-col items-center justify-center px-4">
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
                        :disabled="loading"
                        @click="search"
                        class="relative px-8 overflow-hidden transition-all"
                        :class="loading ? 'scale-95' : 'hover:scale-105'"
                    >
                        <span v-if="loading" class="inline-flex items-center gap-2">
                            <span class="inline-block h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
                            Searching...
                        </span>
                        <span v-else>Search</span>
                    </Button>
                </div>
            </div>

            <!-- Results -->
            <div v-if="searched" class="mt-12 w-full max-w-5xl">
                <div v-if="loading" class="flex flex-col items-center gap-3 py-12">
                    <span class="inline-block h-8 w-8 animate-spin rounded-full border-2 border-primary border-t-transparent" />
                    <p class="text-sm text-muted-foreground animate-pulse">Finding the best spots...</p>
                </div>
                <Card v-else-if="searchError" class="border-destructive bg-destructive/10">
                    <CardContent class="flex flex-col items-center gap-4 py-8 text-center">
                        <div class="flex items-center gap-2">
                            <Badge variant="destructive">Search Error</Badge>
                            <span class="text-muted-foreground">{{ searchError }}</span>
                        </div>
                        <Button @click="search">Try Again</Button>
                    </CardContent>
                </Card>
                <div v-else-if="restaurants.length === 0" class="py-8 text-center text-muted-foreground">
                    No restaurants found. Try a different cuisine or location.
                </div>
                <div v-else>
                    <!-- Sort control -->
                    <div class="mb-4 flex items-center justify-end gap-2">
                        <label for="sort-select" class="text-sm text-muted-foreground">Sort by:</label>
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

                    <div class="flex flex-col gap-3">
                    <RestaurantCard
                        v-for="(restaurant, index) in restaurants"
                        :key="restaurant.id"
                        :restaurant="restaurant"
                        :rank="index + 1"
                    />
                    <Button
                        v-if="nextPageUrl"
                        variant="outline"
                        :disabled="loadingMore"
                        class="mx-auto mt-2"
                        @click="loadMore"
                    >
                        <span v-if="loadingMore" class="inline-flex items-center gap-2">
                            <span class="inline-block h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
                            Loading...
                        </span>
                        <span v-else>Load More</span>
                    </Button>

                    <!-- Load more error -->
                    <Card v-if="loadMoreError" class="mx-auto mt-2 border-destructive bg-destructive/10">
                        <CardContent class="flex items-center justify-between py-3">
                            <div class="flex items-center gap-2">
                                <Badge variant="destructive" class="text-xs">Load Error</Badge>
                                <span class="text-sm text-muted-foreground">{{ loadMoreError }}</span>
                            </div>
                            <div class="flex gap-2">
                                <Button variant="ghost" size="sm" @click="loadMoreError = null">
                                    Dismiss
                                </Button>
                                <Button size="sm" @click="loadMore">
                                    Retry
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>
        </div>
    </div>
</template>
