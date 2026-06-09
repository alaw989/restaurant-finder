<script setup lang="ts">
import { ref, computed } from 'vue'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'

interface Location {
    city: string | null
    state: string | null
}

const props = defineProps<{
    location: Location | null
}>()

const emit = defineEmits<{
    update: [location: Location]
}>()

const open = ref(false)
const cityInput = ref(props.location?.city ?? '')
const stateInput = ref(props.location?.state ?? '')

const displayText = computed(() => {
    if (props.location?.city && props.location?.state) {
        return `${props.location.city}, ${props.location.state}`
    }
    if (props.location?.city) return props.location.city
    return 'your city'
})

function save() {
    emit('update', {
        city: cityInput.value || null,
        state: stateInput.value || null,
    })
    open.value = false
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <button
                class="inline-flex items-center gap-1 border-b-2 border-foreground/30 px-1 font-semibold text-foreground transition-colors hover:border-foreground focus:outline-none"
            >
                {{ displayText }}
                <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 opacity-50" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                </svg>
            </button>
        </PopoverTrigger>
        <PopoverContent class="w-72 p-3" align="start">
            <div class="flex flex-col gap-2">
                <Input v-model="cityInput" placeholder="City" class="h-9" />
                <Input v-model="stateInput" placeholder="State" class="h-9" />
                <Button size="sm" @click="save" class="w-full">Apply</Button>
            </div>
        </PopoverContent>
    </Popover>
</template>
