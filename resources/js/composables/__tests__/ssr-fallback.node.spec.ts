// @vitest-environment node
// Exercises the `typeof window === 'undefined'` branch of the SSR-safe base
// URL helpers — the guard that keeps spec-063's Inertia SSR from crashing.
// Runs in the node env (no window); every other test file uses jsdom.
import { describe, it, expect } from 'vitest';
import { useBaseUrl } from '@/composables/useBaseUrl';
import { getBaseUrl } from '@/lib/api';

describe('SSR fallback (no window)', () => {
    it('useBaseUrl falls back to the production origin', () => {
        expect(useBaseUrl().value).toBe('https://ipop360.vp-associates.com');
    });

    it('getBaseUrl falls back to the production origin', () => {
        expect(getBaseUrl()).toBe('https://ipop360.vp-associates.com');
    });
});
