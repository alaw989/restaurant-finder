<script setup lang="ts">
import { ref, onMounted, onUnmounted, watch } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'

const props = defineProps<{
  lat: number | null
  lng: number | null
  name: string
  address?: string | null
}>()

const mapContainer = ref<HTMLElement | null>(null)
let mapInstance: L.Map | null = null

function initMap() {
  if (!mapContainer.value || props.lat == null || props.lng == null) return

  mapInstance = L.map(mapContainer.value, {
    center: [props.lat, props.lng],
    zoom: 16,
    zoomControl: true,
    attributionControl: true,
  })

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="https://openstreetmap.org/copyright">OpenStreetMap</a>',
  }).addTo(mapInstance)

  const icon = L.divIcon({
    className: 'custom-pin',
    html: `<div style="background:#ef4444;border:3px solid white;border-radius:50%;width:18px;height:18px;box-shadow:0 2px 6px rgba(0,0,0,0.3)"></div>`,
    iconSize: [18, 18],
    iconAnchor: [9, 9],
  })

  L.marker([props.lat, props.lng], { icon })
    .addTo(mapInstance)
    .bindPopup(`<b>${props.name}</b>`)

  // Fit bounds to show a small area around the marker
  mapInstance.fitBounds([
    [props.lat - 0.005, props.lng - 0.005],
    [props.lat + 0.005, props.lng + 0.005],
  ])
}

function destroyMap() {
  if (mapInstance) {
    mapInstance.remove()
    mapInstance = null
  }
}

function openDirections() {
  if (props.lat != null && props.lng != null) {
    const dest = `${props.lat},${props.lng}`
    window.open(`https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(dest)}`, '_blank')
  }
}

onMounted(() => {
  setTimeout(initMap, 200)
})

onUnmounted(destroyMap)

watch(
  () => [props.lat, props.lng] as const,
  () => {
    destroyMap()
    if (props.lat != null && props.lng != null) {
      setTimeout(initMap, 200)
    }
  }
)
</script>

<template>
  <div class="overflow-hidden rounded-xl border border-border bg-card">
    <div ref="mapContainer" class="h-72 w-full sm:h-96" />
    <div v-if="lat && lng" class="border-t border-border px-4 py-2">
      <button
        class="inline-flex items-center gap-1.5 text-sm font-medium text-primary hover:text-primary/80 transition-colors"
        @click="openDirections"
      >
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18 3 12l6-6"/><path d="M15 6l6 6-6 6"/></svg>
        Get Directions
      </button>
    </div>
  </div>
</template>

<style scoped>
.custom-pin {
  background: none;
  border: none;
}
</style>
