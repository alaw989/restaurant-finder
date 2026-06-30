import { describe, it, expect, beforeEach, vi } from 'vitest';
import { usePersistedLocation } from '@/composables/usePersistedLocation';

// Mirrors usePersistedLocation.STORAGE_KEY (not exported). The spec-045
// city/state/coords round-trip format is the behavior under test.
const STORAGE_KEY = 'foodrank_location';

beforeEach(() => {
    localStorage.clear();
});

describe('usePersistedLocation (spec-045 city + coords round-trip)', () => {
    it('round-trips city/state/lat/lng through localStorage', () => {
        const writer = usePersistedLocation();
        writer.persistLocation('Austin', 'TX', 30.2711, -97.7437);

        const reader = usePersistedLocation();
        reader.restore();

        expect(reader.location.value).toEqual({ city: 'Austin', state: 'TX' });
        expect(reader.lat.value).toBe(30.2711);
        expect(reader.lng.value).toBe(-97.7437);
    });

    it('restores a legacy city-only save without clobbering initial coords', () => {
        localStorage.setItem(STORAGE_KEY, JSON.stringify({ city: 'Austin', state: 'TX' }));

        const { location, lat, lng, restore } = usePersistedLocation(
            { city: null, state: null },
            { lat: 1, lng: 2 }, // a prior IP-guess coord, say
        );
        restore();

        expect(location.value.city).toBe('Austin');
        // no coords in the save → keep the initial coord refs untouched
        expect(lat.value).toBe(1);
        expect(lng.value).toBe(2);
    });

    it('falls through to onNoSavedLocation when storage is empty', () => {
        const cb = vi.fn();
        const { restore } = usePersistedLocation();
        restore(cb);
        expect(cb).toHaveBeenCalledOnce();
    });

    it('falls through to onNoSavedLocation on corrupt JSON (does not throw)', () => {
        localStorage.setItem(STORAGE_KEY, '{not valid json');
        const cb = vi.fn();
        const { restore } = usePersistedLocation();
        expect(() => restore(cb)).not.toThrow();
        expect(cb).toHaveBeenCalledOnce();
    });

    it('does not re-prompt when a saved city already exists', () => {
        const { persistLocation, restore } = usePersistedLocation();
        persistLocation('Austin', 'TX', 30.27, -97.74);

        const cb = vi.fn();
        const { restore: r2 } = usePersistedLocation();
        r2(cb);
        expect(cb).not.toHaveBeenCalled();
    });

    it('restores coords but still re-prompts when city is null yet coords are present', () => {
        // Plausible: a prior search resolved coords but no city. The
        // "don't re-prompt" guard keys on a truthy city, so GPS still fires.
        localStorage.setItem(
            STORAGE_KEY,
            JSON.stringify({ city: null, state: 'TX', lat: 30.2, lng: -97.7 }),
        );

        const { location, lat, lng, restore } = usePersistedLocation();
        const cb = vi.fn();
        restore(cb);

        expect(lat.value).toBe(30.2);
        expect(lng.value).toBe(-97.7);
        expect(location.value.city).toBeNull();
        expect(cb).toHaveBeenCalledOnce();
    });
});
