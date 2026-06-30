<script setup lang="ts">
import { Button } from '@/components/ui/button'
import CuisinePicker from '@/Components/CuisinePicker.vue'
import LocationPicker from '@/Components/LocationPicker.vue'
import BrandLogo from '@/Components/BrandLogo.vue'

interface Category {
    id: number
    name: string
    slug: string
    icon: string | null
    cuisines: any[]
}

interface Location {
    city: string | null
    state: string | null
}

interface Props {
    categories: Category[]
    location: Location
    detectingLocation: boolean
}

interface Emits {
    (e: 'cuisineSelect', payload: { category: string; cuisine?: string; label: string }): void
    (e: 'locationUpdate', location: Location): void
    (e: 'coords', lat: number, lng: number): void
    (e: 'detect'): void
    (e: 'search'): void
}

defineProps<Props>()
const emit = defineEmits<Emits>()

function onCuisineSelect(payload: { category: string; cuisine?: string; label: string }) {
    emit('cuisineSelect', payload)
}

function onLocationUpdate(newLocation: Location) {
    emit('locationUpdate', newLocation)
}

function onCoords(lt: number, lg: number) {
    emit('coords', lt, lg)
}

function onDetect() {
    emit('detect')
}
</script>

<template>
    <div class="flex flex-1 flex-col items-center justify-center px-4">
        <div class="w-full max-w-4xl text-center">
            <!-- Logo -->
            <a href="/" class="mb-8 inline-block" aria-label="iPop360 home" @click.prevent="$emit('search')">
                <BrandLogo class="text-[8rem]" />
            </a>

            <!-- Dynamic sentence -->
            <h2 class="mt-8 flex flex-wrap items-center justify-center gap-x-2 text-3xl font-medium leading-relaxed sm:text-4xl">
                <span>Find the most Popular</span>
                <CuisinePicker
                    :categories="categories"
                    @select="onCuisineSelect"
                />
                <span>Restaurants in</span>
                <LocationPicker
                    :location="location"
                    :detecting="detectingLocation"
                    @update="onLocationUpdate"
                    @coords="onCoords"
                    @detect="onDetect"
                />
            </h2>

            <!-- Search button -->
            <div class="mt-8">
                <Button
                    size="lg"
                    :disabled="detectingLocation"
                    @click="$emit('search')"
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
</template>
