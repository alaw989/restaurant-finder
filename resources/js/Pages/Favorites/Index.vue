<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import RestaurantCard from '@/Components/RestaurantCard.vue';
import { Heart } from '@lucide/vue';
import { useSeo } from '@/composables/useSeo';
import { useBaseUrl } from '@/composables/useBaseUrl';
import SeoMeta from '@/Components/SeoMeta.vue';
import type { Restaurant } from '@/types/restaurant';

const props = defineProps<{
    favorites: Restaurant[];
}>();

// SEO
const baseUrl = useBaseUrl()

const seoData = computed(() => {
    return useSeo({
        title: 'My Favorites | iPop360',
        description: 'Your saved restaurants — log in to sync across devices. View and manage your favorite dining spots all in one place.',
        url: `${baseUrl.value}/favorites`,
        type: 'website',
    })
})
</script>

<template>
    <AppLayout>
        <SeoMeta :seoData="seoData" />

        <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
            <!-- Header -->
            <div class="mb-8">
                <a
                    href="/"
                    class="text-sm text-muted-foreground hover:text-foreground transition-colors"
                >
                    ← Back to search
                </a>
                <div class="mt-4 flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-primary/10">
                        <Heart class="h-5 w-5 text-primary fill-primary" />
                    </div>
                    <h1 class="text-3xl font-bold text-foreground">
                        My Favorites
                    </h1>
                </div>
                <p class="mt-2 text-muted-foreground">
                    Your saved restaurants — log in to sync across devices.
                </p>
            </div>

            <!-- Empty state -->
            <div
                v-if="favorites.length === 0"
                class="rounded-lg border border-border bg-card p-12 text-center"
            >
                <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-muted">
                    <Heart class="h-8 w-8 text-muted-foreground" />
                </div>
                <h2 class="mt-4 text-lg font-semibold text-foreground">No saved restaurants yet</h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    Click the heart icon on any restaurant to save it here.
                </p>
                <a
                    href="/"
                    class="mt-6 inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90"
                >
                    Browse restaurants
                </a>
            </div>

            <!-- Favorites grid -->
            <div v-else class="grid grid-cols-1 gap-x-5 gap-y-7 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                <RestaurantCard
                    v-for="(restaurant, index) in favorites"
                    :key="restaurant.id"
                    :restaurant="restaurant"
                    :rank="index + 1"
                />
            </div>
        </div>
    </AppLayout>
</template>
