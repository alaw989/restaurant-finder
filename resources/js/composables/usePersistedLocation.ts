import { ref, onMounted, type Ref } from 'vue';

interface Location {
    city: string | null;
    state: string | null;
}

interface PersistedLocationData {
    city: string | null;
    state: string | null;
    lat: number | null;
    lng: number | null;
}

interface PersistedLocationResult {
    location: Ref<Location>;
    lat: Ref<number | null>;
    lng: Ref<number | null>;
    persistLocation: (city: string | null, state: string | null, lat: number | null, lng: number | null) => void;
    restore: (onNoSavedLocation?: () => void) => void;
}

const STORAGE_KEY = 'foodrank_location';

/**
 * Composable for persisting and restoring location data from localStorage.
 * Handles the city/state/coords round-trip format from spec-045.
 */
export function usePersistedLocation(initialLocation?: Location | null, initialCoords?: { lat: number; lng: number } | null): PersistedLocationResult {
    const location = ref<Location>(initialLocation ?? { city: null, state: null });
    const lat = ref<number | null>(initialCoords?.lat ?? null);
    const lng = ref<number | null>(initialCoords?.lng ?? null);

    /**
     * Persist city/state AND coords together so a reload restores the exact spot
     * the user searched. Previously the city came from localStorage but the coords
     * came from the server's IP guess → a reload recentered the search on the
     * wrong place.
     */
    function persistLocation(city: string | null, state: string | null, lt: number | null, lg: number | null): void {
        localStorage.setItem(STORAGE_KEY, JSON.stringify({
            city,
            state,
            lat: lt,
            lng: lg,
        }));
    }

    /**
     * Restore saved location on mount.
     * Returns a function that can be called to auto-detect via GPS if no save exists.
     */
    function restore(onNoSavedLocation?: () => void): void {
        const savedLocation = localStorage.getItem(STORAGE_KEY);
        if (savedLocation) {
            try {
                const parsed = JSON.parse(savedLocation) as PersistedLocationData;
                // Assign city/state explicitly — NOT the whole blob, which now also
                // carries lat/lng (don't leak those onto the Location ref).
                location.value = {
                    city: parsed.city ?? null,
                    state: parsed.state ?? null,
                };
                // Restore saved coords too (closes the reload city/coords desync).
                // Legacy city-only saves simply keep the IP-guess coords.
                if (parsed.lat != null && parsed.lng != null) {
                    lat.value = parsed.lat;
                    lng.value = parsed.lng;
                }
                if (parsed.city) return; // Don't re-prompt if user already has a saved location
            } catch {
                // Invalid JSON, continue to auto-detect
            }
        }

        // Auto-detect via GPS if no saved location or restore failed
        if (onNoSavedLocation) {
            onNoSavedLocation();
        }
    }

    return {
        location,
        lat,
        lng,
        persistLocation,
        restore,
    };
}
