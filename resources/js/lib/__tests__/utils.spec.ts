import { describe, it, expect } from 'vitest';
import { cn } from '@/lib/utils';

describe('cn (clsx + tailwind-merge)', () => {
    it('joins multiple class tokens', () => {
        expect(cn('px-2', 'py-1', 'text-red')).toBe('px-2 py-1 text-red');
    });

    it('lets tailwind-merge resolve conflicting utilities (last wins)', () => {
        expect(cn('px-2', 'px-4')).toBe('px-4');
        expect(cn('text-sm', 'text-lg')).toBe('text-lg');
    });

    it('drops falsy / conditional inputs', () => {
        expect(cn('a', false, null, undefined, '', 'b')).toBe('a b');
    });

    it('handles plain-object clsx conditionals', () => {
        expect(cn('base', { hidden: false, visible: true })).toBe('base visible');
    });
});
