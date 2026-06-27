<script setup lang="ts">
import { computed } from 'vue'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Utensils, X, Search } from '@lucide/vue'
import RestaurantCard from '@/Components/RestaurantCard.vue'

import type { Restaurant } from '@/types/restaurant'

type Phase = 'idle' | 'searching' | 'results' | 'empty' | 'error'

interface SortOption {
    value: string
    label: string
}

interface Props {
    phase: Phase
    restaurants: Restaurant[]
    resultCount: number
    sort: string
    sortOptions: SortOption[]
    nextPageUrl: string | null
    searchError: string | null
    loadMoreError: string | null
    lat: number | null
    lng: number | null
    selectedCuisine: string | undefined
    shouldStagger: boolean
    isResorting: boolean
}

interface Emits {
    (e: 'update:sort', value: string): void
    (e: 'resort'): void
    (e: 'loadMore'): void
    (e: 'resetToIdle'): void
    (e: 'dismissLoadMoreError'): void
    (e: 'search'): void
}

const props = defineProps<Props>()
const emit = defineEmits<Emits>()

function onSortChange(event: Event) {
    const target = event.target as HTMLSelectElement
    emit('update:sort', target.value)
    emit('resort')
}

function onLoadMore() {
    emit('loadMore')
}

function onResetToIdle() {
    emit('resetToIdle')
}

function onDismissLoadMoreError() {
    emit('dismissLoadMoreError')
}
</script>

<template>
    <!-- Results area (all non-idle phases) -->
    <div class="mx-auto w-full px-4 pb-8 pt-6">
        <!-- Max width only when in results phase. `relative` anchors
             the absolute-positioned spinner leave (state-swap). -->
        <div class="mx-auto max-w-7xl relative">
            <!-- Inner state swap: spinner↔grid crossfade (no
                 mode="out-in" → no blank beat between phases). -->
            <Transition name="state-swap">
                <!-- Loading spinner (searching phase) -->
                <div v-if="phase === 'searching'" key="loading" class="loading-block">
                    <!-- `.spinner-enter` (entrance pop) wraps the ring so it does NOT
                         share an element with `animate-spin`. Both set the `animation`
                         shorthand, so on one node only one survives — putting them
                         together made the ring fade in and never rotate. -->
                    <span class="spinner-enter">
                        <span class="inline-block h-8 w-8 animate-spin rounded-full border-2 border-primary border-t-transparent" />
                    </span>
                    <p class="animate-pulse text-sm text-muted-foreground">Finding the best spots...</p>
                </div>

                <!-- Error state -->
                <Card v-else-if="phase === 'error'" key="error" class="border-destructive bg-destructive/10">
                    <CardContent class="flex flex-col items-center gap-4 py-8 text-center">
                        <div class="flex items-center gap-2">
                            <Badge variant="destructive">Search Error</Badge>
                            <span class="text-muted-foreground">{{ searchError }}</span>
                        </div>
                        <div class="flex gap-2">
                            <Button variant="outline" @click="onResetToIdle">
                                Start Over
                            </Button>
                            <Button @click="$emit('search')">
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
                    <Button variant="outline" class="mt-4" @click="onResetToIdle">
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
                                :value="sort"
                                @change="onSortChange"
                                class="rounded-md border border-input bg-background px-3 py-1.5 text-sm ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                            >
                                <option v-for="option in sortOptions" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <!-- Card grid -->
                    <div
                        class="grid grid-cols-1 gap-x-5 gap-y-7 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4 transition-opacity duration-150"
                        :class="isResorting ? 'opacity-40' : 'opacity-100'"
                    >
                        <RestaurantCard
                            v-for="(restaurant, index) in restaurants"
                            :key="restaurant.id"
                            :restaurant="restaurant"
                            :rank="index + 1"
                            :search-lat="lat"
                            :search-lng="lng"
                            :cuisine="selectedCuisine"
                            :stagger="shouldStagger"
                        />
                    </div>

                    <!-- Load more -->
                    <div v-if="nextPageUrl || loadMoreError" class="mt-8 flex flex-col items-center gap-3">
                        <Button
                            v-if="nextPageUrl"
                            variant="outline"
                            @click="onLoadMore"
                            class="rounded-full px-8"
                        >
                            Load More
                        </Button>
                        <Card v-if="loadMoreError" class="border-destructive bg-destructive/10">
                            <CardContent class="flex items-center gap-3 py-3">
                                <Badge variant="destructive" class="text-xs">Load Error</Badge>
                                <span class="text-sm text-muted-foreground">{{ loadMoreError }}</span>
                                <div class="ml-auto flex gap-2">
                                    <Button variant="ghost" size="sm" aria-label="Dismiss" @click="onDismissLoadMoreError">
                                        <X class="h-4 w-4" />
                                    </Button>
                                    <Button size="sm" @click="onLoadMore">
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
</template>
