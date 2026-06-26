import type { ComputedRef } from 'vue';
import { usePage } from '@inertiajs/vue3';

export interface SeoOptions {
    title: string;
    description: string;
    url?: string;
    image?: string;
    type?: 'website' | 'restaurant' | 'article';
    structuredData?: Record<string, unknown>;
}

export function useSeo(options: SeoOptions) {
    const page = usePage();
    const rawUrl = options.url || (typeof window !== 'undefined' ? window.location.href : '');

    // Strip tracking params for canonical URL
    let canonicalUrl = rawUrl;
    try {
        const u = new URL(rawUrl);
        const params = new URLSearchParams();
        if (u.searchParams.has('cuisine')) {
            params.set('cuisine', u.searchParams.get('cuisine')!);
        }
        if (u.searchParams.has('lat') && u.searchParams.has('lng')) {
            params.set('lat', u.searchParams.get('lat')!);
            params.set('lng', u.searchParams.get('lng')!);
        }
        u.search = params.toString();
        canonicalUrl = u.toString();
    } catch {
        // Keep raw URL on parse error
    }

    const siteName = 'iPop360';
    const defaultImage = options.image || '/img/ipop360-og.png';
    const twitterCard = options.image ? 'summary_large_image' : 'summary';

    return {
        title: options.title,
        description: options.description,
        canonical: canonicalUrl,
        ogTitle: options.title,
        ogDescription: options.description,
        ogType: options.type || 'website',
        ogUrl: canonicalUrl,
        ogSiteName: siteName,
        ogImage: defaultImage,
        ogImageAlt: options.type === 'restaurant' ? options.title : `${siteName} logo`,
        twitterCard: twitterCard,
        twitterTitle: options.title,
        twitterDescription: options.description,
        twitterImage: defaultImage,
    };
}

// Helper functions for generating structured data
export function generateWebSiteJsonLd(url: string, name: string) {
    return {
        '@context': 'https://schema.org',
        '@type': 'WebSite',
        name,
        url,
        potentialAction: {
            '@type': 'SearchAction',
            target: `${url}?cuisine={{search_term_string}}`,
            'query-input': 'required name=search_term_string',
        },
    };
}

export function generateOrganizationJsonLd(url: string, name: string) {
    return {
        '@context': 'https://schema.org',
        '@type': 'Organization',
        name,
        url,
        logo: `${url}/img/ipop360-logo.png`,
    };
}

export function generateItemListJsonLd(items: Array<{ name: string; url: string; position: number }>) {
    return {
        '@context': 'https://schema.org',
        '@type': 'ItemList',
        itemListElement: items.map(item => ({
            '@type': 'ListItem',
            position: item.position,
            name: item.name,
            url: item.url,
        })),
    };
}

export function generateRestaurantJsonLd(restaurant: Record<string, unknown>) {
    const base: Record<string, unknown> = {
        '@context': 'https://schema.org',
        '@type': 'Restaurant',
        name: restaurant.name,
        url: restaurant.url,
    };

    if (restaurant.address) {
        base.address = {
            '@type': 'PostalAddress',
            streetAddress: restaurant.address,
            addressLocality: restaurant.city,
            addressRegion: restaurant.state,
            addressCountry: 'US',
        };
    }

    if (restaurant.latitude && restaurant.longitude) {
        base.geo = {
            '@type': 'GeoCoordinates',
            latitude: restaurant.latitude,
            longitude: restaurant.longitude,
        };
    }

    if (restaurant.phone) {
        base.telephone = restaurant.phone;
    }

    if (restaurant.google_rating && typeof restaurant.google_rating === 'number') {
        base.aggregateRating = {
            '@type': 'AggregateRating',
            ratingValue: restaurant.google_rating,
            ratingCount: restaurant.google_review_count || 0,
            bestRating: 5,
            worstRating: 1,
        };
    }

    if (restaurant.cuisines && Array.isArray(restaurant.cuisines)) {
        base.servesCuisine = restaurant.cuisines
            .map((c: Record<string, unknown>) => c.name)
            .filter(Boolean)
            .join(', ');
    }

    if (restaurant.price_range) {
        base.priceRange = restaurant.price_range;
    }

    return base;
}
