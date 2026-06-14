<script setup lang="ts">
import { ref, computed } from 'vue'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command'

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

const props = defineProps<{
    categories: Category[]
}>()

const emit = defineEmits<{
    select: [payload: { category: string; cuisine?: string; label: string }]
}>()

const open = ref(false)
const drillCategory = ref<Category | null>(null)
const selectedLabel = ref<string | null>(null)

const displayText = computed(() => selectedLabel.value ?? 'any cuisine')

function selectCategory(cat: Category) {
    drillCategory.value = cat
}

function selectCuisine(cuisine: Cuisine) {
    const cat = drillCategory.value!
    selectedLabel.value = `${cat.name} ▸ ${cuisine.name}`
    open.value = false
    drillCategory.value = null
    emit('select', {
        category: cat.slug,
        cuisine: cuisine.slug,
        label: selectedLabel.value!,
    })
}

function confirmCategory(cat: Category) {
    selectedLabel.value = cat.name
    open.value = false
    drillCategory.value = null
    emit('select', {
        category: cat.slug,
        label: selectedLabel.value!,
    })
}

function goBack() {
    drillCategory.value = null
}

function clearSelection() {
    selectedLabel.value = null
    drillCategory.value = null
    open.value = false
    emit('select', { category: '', label: 'any cuisine' })
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <button
                class="inline-flex items-center gap-1 border-b-2 border-foreground/30 px-1 font-semibold text-foreground transition-colors hover:border-foreground focus:outline-none"
                :class="{ 'text-muted-foreground': !selectedLabel }"
            >
                {{ displayText }}
                <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 opacity-50" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                </svg>
            </button>
        </PopoverTrigger>
        <PopoverContent class="w-72 p-0" align="start">
            <Command v-if="!drillCategory">
                <CommandInput placeholder="Search cuisines..." />
                <CommandList>
                    <CommandEmpty>No categories found.</CommandEmpty>
                    <CommandGroup heading="Categories">
                        <CommandItem
                            v-for="cat in categories"
                            :key="cat.id"
                            :value="cat.name"
                            @select="selectCategory(cat)"
                        >
                            <span class="mr-2">{{ cat.icon }}</span>
                            <span class="flex-1">{{ cat.name }}</span>
                            <span class="text-xs text-muted-foreground">{{ cat.cuisines.length }}</span>
                        </CommandItem>
                    </CommandGroup>
                    <CommandGroup v-if="selectedLabel">
                        <CommandItem value="__clear" @select="clearSelection" class="text-muted-foreground">
                            ✕ Clear selection
                        </CommandItem>
                    </CommandGroup>
                </CommandList>
            </Command>
            <Command v-else>
                <CommandInput :placeholder="`Search ${drillCategory.name} cuisines...`" />
                <CommandList>
                    <CommandEmpty>No cuisines found.</CommandEmpty>
                    <CommandGroup>
                        <CommandItem value="__back" @select="goBack" class="text-muted-foreground">
                            ← Back to categories
                        </CommandItem>
                        <CommandItem
                            :value="`all ${drillCategory.name}`"
                            @select="confirmCategory(drillCategory!)"
                        >
                            <span class="mr-2">{{ drillCategory.icon }}</span>
                            <span class="font-medium">All {{ drillCategory.name }}</span>
                        </CommandItem>
                        <CommandItem
                            v-for="cuisine in drillCategory.cuisines"
                            :key="cuisine.id"
                            :value="cuisine.name"
                            @select="selectCuisine(cuisine)"
                        >
                            <span class="mr-2">{{ cuisine.icon || '•' }}</span>
                            {{ cuisine.name }}
                        </CommandItem>
                    </CommandGroup>
                </CommandList>
            </Command>
        </PopoverContent>
    </Popover>
</template>
