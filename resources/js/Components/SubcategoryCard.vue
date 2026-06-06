<script setup lang="ts">
import { Card, CardContent } from '@/components/ui/card';

defineProps<{
    cuisine: {
        id: number;
        name: string;
        slug: string;
        description: string | null;
        icon: string | null;
    };
}>();

defineEmits<{
    select: [slug: string];
}>();
</script>

<template>
    <Card
        class="cursor-pointer transition-all hover:shadow-lg hover:-translate-y-1"
        @click="$emit('select', cuisine.slug)"
    >
        <CardContent class="flex items-center gap-4 p-4">
            <span class="text-3xl">{{ cuisine.icon }}</span>
            <div>
                <h3 class="font-semibold text-foreground">{{ cuisine.name }}</h3>
                <p v-if="cuisine.description" class="text-sm text-muted-foreground">
                    {{ cuisine.description }}
                </p>
            </div>
        </CardContent>
    </Card>
</template>
