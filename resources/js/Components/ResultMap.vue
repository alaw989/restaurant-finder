<script setup lang="ts">
import { ref, onMounted, onUnmounted, watch } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'

const props = defineProps<{
  lat: number | null
  lng: number | null
  name: string
}>()

const mapContainer = ref<HTMLElement | null>(null)
let mapInstance: L.Map | null = null

function initMap() {
  if (!mapContainer.value || props.lat == null || props.lng == null) return

  mapInstance = L.map(mapContainer.value, {
    center: [props.lat, props.lng],
    zoom: 15,
    zoomControl: false,
    dragging: false,
    scrollWheelZoom: false,
    doubleClickZoom: false,
    touchZoom: false,
    keyboard: false,
    attributionControl: false,
  })

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
  }).addTo(mapInstance)

  const icon = L.divIcon({
    className: 'custom-marker',
    html: '<div style="background:#ef4444;border:2px solid white;border-radius:50%;width:12px;height:12px;box-shadow:0 1px 3px rgba(0,0,0,0.3)"></div>',
    iconSize: [12, 12],
    iconAnchor: [6, 6],
  })

  L.marker([props.lat, props.lng], { icon }).addTo(mapInstance)
}

function destroyMap() {
  if (mapInstance) {
    mapInstance.remove()
    mapInstance = null
  }
}

onMounted(() => {
  setTimeout(initMap, 100)
})

onUnmounted(destroyMap)

watch(
  () => [props.lat, props.lng] as const,
  () => {
    destroyMap()
    if (props.lat != null && props.lng != null) {
      setTimeout(initMap, 100)
    }
  }
)
</script>

<template>
  <div
    ref="mapContainer"
    class="h-16 w-full overflow-hidden rounded-lg bg-muted"
    :class="{ 'opacity-0': !lat || !lng }"
  />
</template>

<style scoped>
.custom-marker {
  background: none;
  border: none;
}
</style>
