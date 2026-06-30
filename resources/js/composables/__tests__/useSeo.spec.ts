import { describe, it, expect, vi } from 'vitest';

// useSeo() calls usePage(); the JSON-LD generators don't, but mocking the
// Inertia import once keeps every test self-contained.
vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));

import {
    useSeo,
    generateWebSiteJsonLd,
    generateOrganizationJsonLd,
    generateItemListJsonLd,
    generateRestaurantJsonLd,
} from '@/composables/useSeo';

describe('JSON-LD generators (pure shape)', () => {
    it('generateWebSiteJsonLd emits a WebSite + SearchAction', () => {
        const ld = generateWebSiteJsonLd('https://ipop360.test', 'iPop360');
        expect(ld['@type']).toBe('WebSite');
        expect(ld.name).toBe('iPop360');
        expect(ld.url).toBe('https://ipop360.test');
        expect(ld.potentialAction['@type']).toBe('SearchAction');
        expect(ld.potentialAction.target).toBe(
            'https://ipop360.test?cuisine={{search_term_string}}',
        );
    });

    it('generateOrganizationJsonLd emits an Organization with a logo url', () => {
        const ld = generateOrganizationJsonLd('https://ipop360.test', 'iPop360');
        expect(ld['@type']).toBe('Organization');
        expect(ld.logo).toBe('https://ipop360.test/img/ipop360-logo.png');
    });

    it('generateItemListJsonLd maps items to ListItem entries with positions', () => {
        const ld = generateItemListJsonLd([
            { name: 'A', url: '/a', position: 1 },
            { name: 'B', url: '/b', position: 2 },
        ]);
        expect(ld['@type']).toBe('ItemList');
        expect(ld.itemListElement).toHaveLength(2);
        expect(ld.itemListElement[0]).toEqual({
            '@type': 'ListItem',
            position: 1,
            name: 'A',
            url: '/a',
        });
    });

    it('generateRestaurantJsonLd includes address / geo / rating / cuisine / price when present', () => {
        const ld = generateRestaurantJsonLd({
            name: 'Casa',
            url: '/r/casa',
            address: '123 Main',
            city: 'Austin',
            state: 'TX',
            latitude: 30.27,
            longitude: -97.74,
            phone: '512-555-1212',
            google_rating: 4.7,
            google_review_count: 1280,
            cuisines: [{ name: 'Mexican' }, { name: 'Tex-Mex' }],
            price_range: '$$',
        });

        expect(ld['@type']).toBe('Restaurant');
        expect(ld['@context']).toBe('https://schema.org');
        // Full-block equality so any dropped/misnamed subfield (streetAddress,
        // addressRegion, addressCountry, telephone, best/worstRating) fails.
        expect(ld.address).toEqual({
            '@type': 'PostalAddress',
            streetAddress: '123 Main',
            addressLocality: 'Austin',
            addressRegion: 'TX',
            addressCountry: 'US',
        });
        expect(ld.telephone).toBe('512-555-1212');
        expect(ld.geo).toEqual({
            '@type': 'GeoCoordinates',
            latitude: 30.27,
            longitude: -97.74,
        });
        expect(ld.aggregateRating).toEqual({
            '@type': 'AggregateRating',
            ratingValue: 4.7,
            ratingCount: 1280,
            bestRating: 5,
            worstRating: 1,
        });
        expect(ld.servesCuisine).toBe('Mexican, Tex-Mex');
        expect(ld.priceRange).toBe('$$');
    });

    it('generateRestaurantJsonLd omits aggregateRating when google_rating is absent', () => {
        const ld = generateRestaurantJsonLd({ name: 'X', url: '/x' });
        expect(ld.aggregateRating).toBeUndefined();
        expect(ld.geo).toBeUndefined();
        expect(ld.address).toBeUndefined();
    });
});

describe('useSeo canonical-URL stripping', () => {
    it('keeps cuisine + lat/lng and drops every other query param', () => {
        const { canonical } = useSeo({
            title: 'T',
            description: 'D',
            url: 'https://ipop360.test/?cuisine=thai&lat=1.2&lng=3.4&foo=bar&utm_source=x',
        });
        expect(canonical).toBe('https://ipop360.test/?cuisine=thai&lat=1.2&lng=3.4');
    });

    it('drops the query string entirely when no cuisine/coords are present', () => {
        const { canonical } = useSeo({
            title: 'T',
            description: 'D',
            url: 'https://ipop360.test/?foo=bar',
        });
        expect(canonical).toBe('https://ipop360.test/');
    });

    it('honors an explicit noindex flag', () => {
        const { noindex } = useSeo({ title: 'T', description: 'D', noindex: true });
        expect(noindex).toBe(true);
    });

    it('defaults twitter card to summary, large image when an image is supplied', () => {
        expect(useSeo({ title: 'T', description: 'D' }).twitterCard).toBe('summary');
        expect(
            useSeo({ title: 'T', description: 'D', image: '/og.png' }).twitterCard,
        ).toBe('summary_large_image');
    });
});
