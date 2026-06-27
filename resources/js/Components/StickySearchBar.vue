<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { Button } from '@/components/ui/button'
import { Search, MapPin } from '@lucide/vue'
import BrandLogo from '@/Components/BrandLogo.vue'

interface Location {
    city: string | null
    state: string | null
}

interface Props {
    location: Location
}

interface Emits {
    (e: 'refineSearch'): void
}

defineProps<Props>()
const emit = defineEmits<Emits>()

function onRefineSearch() {
    emit('refineSearch')
}
</script>

<template>
    <!-- Sticky compact search bar (visible in results phases) -->
    <div class="sticky top-0 z-20 border-b bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/60">
        <div class="mx-auto flex max-w-7xl items-center gap-3 px-4 py-3 sm:gap-4">
            <!-- Logo mark -->
            <Link href="/" @click.prevent="onRefineSearch" class="flex items-center" aria-label="iPop360 home">
                <BrandLogo class="text-[2rem]" />
            </Link>

            <!-- Location (compact cuisine picker removed in spec-044 —
                 it never re-searched; refine via the search icon). -->
            <div class="flex flex-1 items-center gap-1 text-sm text-muted-foreground">
                <MapPin class="h-3.5 w-3.5" />
                <span>{{ location.city || location.state || 'Everywhere' }}</span>
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
            <Button size="icon" variant="ghost" aria-label="Refine search" @click="onRefineSearch">
                <Search class="h-5 w-5" />
            </Button>
        </div>
    </div>
</template>
