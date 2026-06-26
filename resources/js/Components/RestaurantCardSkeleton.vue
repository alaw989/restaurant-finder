<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';
</script>

<template>
    <div class="overflow-hidden rounded-2xl border border-border/60 bg-card">
        <Skeleton class="aspect-[4/3] w-full rounded-none" />
        <div class="space-y-2.5 p-4">
            <Skeleton class="h-4 w-3/4" />
            <Skeleton class="h-3 w-1/2" />
            <Skeleton class="h-3 w-2/3" />
            <div class="flex gap-1.5 pt-1">
                <Skeleton class="h-5 w-12 rounded-full" />
                <Skeleton class="h-5 w-12 rounded-full" />
            </div>
        </div>
    </div>
</template>
