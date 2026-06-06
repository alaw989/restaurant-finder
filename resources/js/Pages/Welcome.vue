<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import CuisineCategoryCard from '@/Components/CuisineCategoryCard.vue';
import { ref, onMounted } from 'vue';

defineProps<{
    categories: Array<{
        id: number;
        name: string;
        slug: string;
        description: string | null;
        icon: string | null;
        cuisines_count: number;
    }>;
}>();

const lat = ref<number | null>(null);
const lng = ref<number | null>(null);
const locating = ref(true);
const geoError = ref<string | null>(null);

onMounted(() => {
    if (!navigator.geolocation) {
        locating.value = false;
        geoError.value = 'Geolocation is not supported by your browser.';
        return;
    }

    navigator.geolocation.getCurrentPosition(
        (position) => {
            lat.value = position.coords.latitude;
            lng.value = position.coords.longitude;
            locating.value = false;
        },
        () => {
            locating.value = false;
            geoError.value = 'Unable to get your location. You can still browse cuisines.';
        },
        { timeout: 10000 }
    );
});

function selectCategory(slug: string) {
    const params: Record<string, string> = {};
    if (lat.value !== null) params.lat = lat.value.toString();
    if (lng.value !== null) params.lng = lng.value.toString();

    router.visit(`/cuisine/${slug}`, { data: params });
}
</script>

<template>
    <AppLayout>
        <Head title="Find Popular Restaurants Near You" />

        <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
            <div class="mb-12 text-center">
                <h1 class="text-4xl font-bold tracking-tight text-foreground sm:text-5xl">
                    Find the Most Popular Restaurants
                </h1>
                <p class="mt-4 text-lg text-muted-foreground max-w-2xl mx-auto">
                    Pick a cuisine category, then a subcategory — see the hottest spots ranked by reviews, ratings, and live busyness data.
                </p>

                <div v-if="locating" class="mt-6 flex items-center justify-center gap-2 text-sm text-muted-foreground">
                    <span class="inline-block h-4 w-4 animate-spin rounded-full border-2 border-muted-foreground border-t-transparent" />
                    Detecting your location...
                </div>
                <p v-else-if="geoError" class="mt-6 text-sm text-amber-600">
                    {{ geoError }}
                </p>
                <p v-else class="mt-6 text-sm text-emerald-600">
                    Location detected — results ranked by proximity and popularity.
                </p>
            </div>

            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4">
                <CuisineCategoryCard
                    v-for="category in categories"
                    :key="category.id"
                    :category="category"
                    @select="selectCategory"
                />
            </div>
        </div>
    </AppLayout>
</template>
