<script setup lang="ts">
import { Card, CardContent } from '@/components/ui/card';

defineProps<{
    category: {
        id: number;
        name: string;
        slug: string;
        description: string | null;
        icon: string | null;
        cuisines_count: number;
    };
}>();

defineEmits<{
    select: [slug: string];
}>();
</script>

<template>
    <Card
        class="cursor-pointer transition-all hover:shadow-lg hover:-translate-y-1"
        @click="$emit('select', category.slug)"
    >
        <CardContent class="flex flex-col items-center gap-3 p-6 text-center">
            <span class="text-4xl">{{ category.icon }}</span>
            <h3 class="text-lg font-semibold text-foreground">{{ category.name }}</h3>
            <p v-if="category.description" class="text-sm text-muted-foreground">
                {{ category.description }}
            </p>
            <span class="text-xs text-muted-foreground">
                {{ category.cuisines_count }} cuisines
            </span>
        </CardContent>
    </Card>
</template>
