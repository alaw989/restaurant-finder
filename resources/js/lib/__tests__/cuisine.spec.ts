import { describe, it, expect } from 'vitest';
import { cuisineGradient, FOOD_FALLBACK_GRADIENT } from '@/lib/cuisine';

describe('cuisineGradient (per-cuisine no-photo backdrop)', () => {
    // Exact-string equality (not a substring match) — #e63946 appears in 5 of
    // the 13 gradients, so toMatch(/#e63946/) would pass a slug-swap regression.
    it('returns the EXACT mapped gradient for a known cuisine slug', () => {
        expect(cuisineGradient('italian')).toBe(
            'linear-gradient(135deg, #e63946 0%, #f1faee 50%, #457b9d 100%)',
        );
    });

    it('returns the exact gradient for a second cuisine (slug-dispatch check)', () => {
        expect(cuisineGradient('mexican')).toBe(
            'linear-gradient(135deg, #f77f00 0%, #fcbf49 20%, #d62828 100%)',
        );
    });

    it('falls back for an unknown cuisine slug', () => {
        expect(cuisineGradient('martian')).toBe(FOOD_FALLBACK_GRADIENT);
    });

    it('falls back for empty / null / undefined (live venues can lack a slug)', () => {
        expect(cuisineGradient('')).toBe(FOOD_FALLBACK_GRADIENT);
        expect(cuisineGradient(null)).toBe(FOOD_FALLBACK_GRADIENT);
        expect(cuisineGradient(undefined)).toBe(FOOD_FALLBACK_GRADIENT);
    });
});
