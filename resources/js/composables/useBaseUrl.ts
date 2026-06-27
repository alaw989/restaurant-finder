/**
 * SSR-safe base URL composable.
 * Provides the current origin (protocol + host) for SEO and canonical URLs.
 * Falls back to the production URL on the server.
 */

import { computed } from 'vue';

/**
 * Get the base URL (protocol + host) for the current request.
 * SSR-safe: uses window.location on client, falls back to production URL on server.
 */
export function useBaseUrl() {
    return computed(() => {
        if (typeof window !== 'undefined') {
            return `${window.location.protocol}//${window.location.host}`;
        }
        return 'https://ipop360.vp-associates.com';
    });
}
