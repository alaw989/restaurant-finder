import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { callPhone, openWebsite, mapsUrl, directionsUrl } from '@/lib/restaurant';

describe('mapsUrl / directionsUrl (pure URL builders)', () => {
    it('mapsUrl encodes name + city', () => {
        expect(mapsUrl('Casa Garcia', 'Austin'))
            .toBe('https://www.google.com/maps/search/?api=1&query=Casa%20Garcia%2C%20Austin');
    });

    it('mapsUrl without a city uses only the name', () => {
        expect(mapsUrl('Casa Garcia'))
            .toBe('https://www.google.com/maps/search/?api=1&query=Casa%20Garcia');
    });

    it('directionsUrl targets lat,lng', () => {
        expect(directionsUrl(30.2711, -97.7437))
            .toBe('https://www.google.com/maps/dir/?api=1&destination=30.2711,-97.7437');
    });
});

describe('openWebsite (scheme normalization + window.open)', () => {
    afterEach(() => vi.unstubAllGlobals());

    it('prepends https:// when the url has no protocol', () => {
        const open = vi.fn();
        vi.stubGlobal('open', open);
        openWebsite('example.com');
        expect(open).toHaveBeenCalledWith('https://example.com', '_blank');
    });

    it('does not double-prepend for an http url', () => {
        const open = vi.fn();
        vi.stubGlobal('open', open);
        openWebsite('http://example.com');
        expect(open).toHaveBeenCalledWith('http://example.com', '_blank');
    });

    it('leaves an https url untouched', () => {
        const open = vi.fn();
        vi.stubGlobal('open', open);
        openWebsite('https://example.com');
        expect(open).toHaveBeenCalledWith('https://example.com', '_blank');
    });
});

describe('callPhone (tel: link)', () => {
    // jsdom locks Location.href (non-configurable), so swap the whole
    // window.location for a plain writable stub for this one test, then
    // restore it.
    let originalLocation: Location;
    const stub: { href: string } = { href: '' };

    beforeEach(() => {
        stub.href = ''; // reset so each test starts clean (stub is module-scoped)
        originalLocation = window.location;
        Object.defineProperty(window, 'location', { configurable: true, value: stub });
    });
    afterEach(() => {
        Object.defineProperty(window, 'location', { configurable: true, value: originalLocation });
    });

    it('sets window.location.href to a tel: URI', () => {
        callPhone('512-555-1234');
        expect(stub.href).toBe('tel:512-555-1234');
    });
});
