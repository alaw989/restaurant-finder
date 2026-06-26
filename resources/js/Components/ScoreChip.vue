<script setup lang="ts">
import { computed } from 'vue';
import { Gauge } from '@lucide/vue';

const props = defineProps<{
    total: number;
}>();

const pct = computed(() => Math.round((props.total ?? 0) * 100));

const tier = computed(() => {
    const t = props.total ?? 0;
    if (t >= 0.8) return 'bg-emerald-500/20 text-emerald-600 dark:text-emerald-400';
    if (t >= 0.6) return 'bg-amber-500/20 text-amber-600 dark:text-amber-400';
    if (t >= 0.4) return 'bg-sky-500/20 text-sky-600 dark:text-sky-400';
    return 'bg-muted text-muted-foreground';
});
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold tabular-nums shadow-sm"
        :class="tier"
    >
        <Gauge class="h-3 w-3" />
        Score {{ pct }}%
    </span>
</template>
