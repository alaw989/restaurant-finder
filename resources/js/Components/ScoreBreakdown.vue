<script setup lang="ts">
import { computed, ref } from 'vue'

interface SignalSegment {
  label: string
  contribution: number
  weight: number
  normalized: number
  color: string
  width: number
}

interface NoDataSegment {
  label: string
  color: string
  width: number
}

type BarSegment = SignalSegment | NoDataSegment

const props = defineProps<{
  breakdown: {
    signals: Array<{
      label: string
      weight: number
      normalized: number
      contribution: number
    }>
    total: number
  }
}>()

const showTooltip = ref(false)

const segmentColors: Record<string, string> = {
  'Yelp Rating': 'bg-amber-500',
  'Yelp Reviews': 'bg-blue-500',
  'Google Rating': 'bg-red-500',
  'Google Reviews': 'bg-red-400',
  'Profile Completeness': 'bg-emerald-500',
  'Award': 'bg-purple-500',
  'Rating': 'bg-orange-500',
  'Reviews': 'bg-cyan-500',
  'Busyness': 'bg-teal-500',
}

const defaultColors = [
  'bg-rose-500', 'bg-sky-500', 'bg-lime-500', 'bg-violet-500',
  'bg-pink-500', 'bg-indigo-500', 'bg-teal-500', 'bg-orange-500',
]

function segmentColor(index: number, label: string): string {
  return segmentColors[label] ?? defaultColors[index % defaultColors.length]
}

const barSegments = computed<BarSegment[]>(() => {
  const total = props.breakdown.total
  if (total <= 0 || props.breakdown.signals.length === 0) {
    return [{ label: 'No data', color: 'bg-muted-foreground/20', width: 100 }]
  }
  const active = props.breakdown.signals.filter(s => s.contribution > 0)
  if (active.length === 0) {
    return [{ label: 'No data', color: 'bg-muted-foreground/20', width: 100 }]
  }
  return active.map((s, i) => ({
    label: s.label,
    contribution: s.contribution,
    weight: s.weight,
    normalized: s.normalized,
    color: segmentColor(i, s.label),
    width: Math.max((s.contribution / total) * 100, 5),
  }))
})

function isSignal(seg: BarSegment): seg is SignalSegment {
  return 'contribution' in seg
}

const tooltipSignals = computed(() =>
  barSegments.value.filter((s): s is SignalSegment => isSignal(s))
)

const scorePercent = computed(() => Math.round(props.breakdown.total * 100))
</script>

<template>
  <div class="relative">
    <div
      class="flex h-2 w-full overflow-hidden rounded-full bg-muted"
      @mouseenter="showTooltip = true"
      @mouseleave="showTooltip = false"
      @focus="showTooltip = true"
      @blur="showTooltip = false"
    >
      <div
        v-for="(seg, i) in barSegments"
        :key="i"
        :style="{ width: seg.width + '%' }"
        :class="[seg.color, i === 0 ? 'rounded-l-full' : '', i === barSegments.length - 1 ? 'rounded-r-full' : '']"
        class="h-full transition-all duration-300 first:rounded-l-full last:rounded-r-full"
      />
    </div>
    <span class="mt-0.5 block text-[10px] font-medium tabular-nums text-muted-foreground">
      Score {{ scorePercent }}%
    </span>

    <div
      v-if="showTooltip && tooltipSignals.length > 0"
      class="absolute bottom-full left-1/2 z-50 mb-2 -translate-x-1/2"
    >
      <div class="rounded-lg border border-border bg-popover px-3 py-2 shadow-lg">
        <p class="mb-1.5 text-xs font-semibold text-popover-foreground">Score Breakdown</p>
        <div class="space-y-1">
          <div
            v-for="seg in tooltipSignals"
            :key="seg.label"
            class="flex items-center gap-2 text-xs"
          >
            <span class="inline-block h-2 w-2 shrink-0 rounded-full" :class="seg.color" />
            <span class="text-muted-foreground">{{ seg.label }}</span>
            <span class="ml-auto tabular-nums text-popover-foreground">
              {{ Math.round(seg.contribution * 100) }}%
            </span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
