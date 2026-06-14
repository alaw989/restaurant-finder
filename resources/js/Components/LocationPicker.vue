<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'

interface Location {
    city: string | null
    state: string | null
}

interface CityResult {
    city: string
    state: string | null
    country: string | null
    lat: number
    lng: number
    display: string | null
}

const props = defineProps<{
    location: Location | null
    detecting?: boolean
}>()

const emit = defineEmits<{
    update: [location: Location]
    coords: [lat: number, lng: number]
}>()

const open = ref(false)
const query = ref('')
const results = ref<CityResult[]>([])
const searching = ref(false)
const selectedIndex = ref(-1)

const displayText = computed(() => {
    if (props.detecting) return 'Detecting...'
    if (props.location?.city && props.location?.state) {
        return `${props.location.city}, ${props.location.state}`
    }
    if (props.location?.city) return props.location.city
    return 'your city'
})

let debounceTimer: ReturnType<typeof setTimeout> | null = null

watch(query, (val) => {
    if (debounceTimer) clearTimeout(debounceTimer)
    if (val.length < 3) {
        results.value = []
        return
    }
    searching.value = true
    debounceTimer = setTimeout(async () => {
        try {
            const res = await fetch(`/api/geocode/search?q=${encodeURIComponent(val)}`)
            const data = await res.json()
            results.value = data ?? []
            selectedIndex.value = -1
        } catch {
            results.value = []
        } finally {
            searching.value = false
        }
    }, 300)
})

function selectResult(result: CityResult) {
    emit('update', { city: result.city, state: result.state })
    emit('coords', result.lat, result.lng)
    open.value = false
    query.value = ''
    results.value = []
}

function onKeydown(e: KeyboardEvent) {
    if (results.value.length === 0) return
    if (e.key === 'ArrowDown') {
        e.preventDefault()
        selectedIndex.value = Math.min(selectedIndex.value + 1, results.value.length - 1)
    } else if (e.key === 'ArrowUp') {
        e.preventDefault()
        selectedIndex.value = Math.max(selectedIndex.value - 1, 0)
    } else if (e.key === 'Enter' && selectedIndex.value >= 0) {
        e.preventDefault()
        selectResult(results.value[selectedIndex.value])
    } else if (e.key === 'Escape') {
        open.value = false
    }
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <button
                class="inline-flex items-center gap-1 border-b-2 border-foreground/30 px-1 font-semibold text-foreground transition-all hover:border-foreground focus:outline-none"
                :class="detecting ? 'animate-pulse text-primary' : ''"
            >
                <svg v-if="detecting" xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 animate-spin text-primary" viewBox="0 0 24 24" fill="none">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
                {{ displayText }}
                <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 opacity-50" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                </svg>
            </button>
        </PopoverTrigger>
        <PopoverContent class="w-80 p-0" align="start">
            <div class="flex flex-col">
                <!-- Search input -->
                <div class="relative border-b border-border">
                    <svg xmlns="http://www.w3.org/2000/svg" class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"/>
                        <path d="m21 21-4.3-4.3"/>
                    </svg>
                    <input
                        v-model="query"
                        @keydown="onKeydown"
                        ref="searchInput"
                        type="text"
                        placeholder="Type your city..."
                        class="w-full bg-transparent py-3 pl-10 pr-4 text-sm outline-none placeholder:text-muted-foreground"
                        autocomplete="off"
                    />
                    <span v-if="searching" class="absolute right-3 top-1/2 -translate-y-1/2">
                        <span class="inline-block h-3.5 w-3.5 animate-spin rounded-full border-2 border-primary border-t-transparent"/>
                    </span>
                </div>

                <!-- Results -->
                <div class="max-h-64 overflow-y-auto">
                    <div v-if="query.length < 3" class="px-4 py-6 text-center text-xs text-muted-foreground">
                        Type at least 3 characters to search
                    </div>
                    <div v-else-if="results.length === 0 && !searching" class="px-4 py-6 text-center text-xs text-muted-foreground">
                        No cities found
                    </div>
                    <button
                        v-for="(result, i) in results"
                        :key="i"
                        @click="selectResult(result)"
                        @mouseenter="selectedIndex = i"
                        class="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm transition-colors hover:bg-accent"
                        :class="selectedIndex === i ? 'bg-accent' : ''"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                            <circle cx="12" cy="10" r="3"/>
                        </svg>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium">{{ result.city }}{{ result.state ? ', ' + result.state : '' }}</p>
                            <p v-if="result.display" class="truncate text-xs text-muted-foreground">{{ result.display }}</p>
                        </div>
                    </button>
                </div>
            </div>
        </PopoverContent>
    </Popover>
</template>
