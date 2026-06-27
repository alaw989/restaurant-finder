import { ref, type Ref } from 'vue';

interface Location {
    city: string | null;
    state: string | null;
}

interface GeolocationResult {
    lat: Ref<number | null>;
    lng: Ref<number | null>;
    location: Ref<Location>;
    detectingLocation: Ref<boolean>;
    geolocationError: Ref<string | null>;
    detectLocation: () => Promise<void>;
}

interface PersistLocationFn {
    (city: string | null, state: string | null, lat: number | null, lng: number | null): void;
}

/**
 * Composable for GPS geolocation and reverse geocoding.
 * Merges the two GPS+reverse-geocode blocks from Welcome.vue into one flow.
 */
export function useGeolocation(persistLocation: PersistLocationFn): GeolocationResult {
    const lat = ref<number | null>(null);
    const lng = ref<number | null>(null);
    const location = ref<Location>({ city: null, state: null });
    const detectingLocation = ref(false);
    const geolocationError = ref<string | null>(null);

    /**
     * Detect location via GPS and reverse geocode.
     * Can be called manually (detectLocation button) or auto-on-mount.
     */
    async function detectLocation(): Promise<void> {
        if (!navigator.geolocation) return;

        detectingLocation.value = true;

        navigator.geolocation.getCurrentPosition(
            async (position) => {
                lat.value = position.coords.latitude;
                lng.value = position.coords.longitude;

                try {
                    const res = await fetch(
                        `/api/geocode?lat=${lat.value}&lng=${lng.value}`
                    );
                    const data = await res.json();
                    if (data.city || data.state) {
                        location.value = {
                            city: data.city ?? null,
                            state: data.state ?? null,
                        };
                        persistLocation(location.value.city, location.value.state, lat.value, lng.value);
                    }
                } catch {
                    // Keep existing coordinates on geocode failure
                }
                detectingLocation.value = false;
            },
            () => {
                detectingLocation.value = false;
                geolocationError.value = 'Unable to detect your location. Please enter it manually.';
            },
            { timeout: 10000, enableHighAccuracy: false }
        );
    }

    return {
        lat,
        lng,
        location,
        detectingLocation,
        geolocationError,
        detectLocation,
    };
}
