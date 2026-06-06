<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { computed } from 'vue';

const props = defineProps<{
    rank: number;
}>();

const variant = computed(() => {
    if (props.rank <= 3) return 'default';
    if (props.rank <= 10) return 'secondary';
    return 'outline';
});

const label = computed(() => {
    if (props.rank === 1) return '#1 Hot';
    if (props.rank <= 3) return `#${props.rank} Hot`;
    if (props.rank <= 10) return `#${props.rank} Trending`;
    return `#${props.rank}`;
});
</script>

<template>
    <Badge :variant="variant">
        {{ label }}
    </Badge>
</template>
