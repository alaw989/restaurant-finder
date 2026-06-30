import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath, URL } from 'node:url';

// Frontend test harness (spec-064). Mirrors the app's path aliases so `@/...`
// and `ziggy-js` resolve identically to the build. Uses jsdom for the DOM-y
// composables (useFavorites localStorage, usePersistedLocation, lib/restaurant
// window side-effects); per-file `// @vitest-environment node` opts into the
// no-window branch for SSR-safety tests.
//
// Test files are excluded from the `vue-tsc` BUILD typecheck (see tsconfig.json
// `exclude`), so a test-only type drift can never break the deploy gate; vitest
// runs them via esbuild. Explicit `import { ... } from 'vitest'` (globals off).
export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
            'ziggy-js': fileURLToPath(new URL('./vendor/tightenco/ziggy', import.meta.url)),
        },
    },
    test: {
        environment: 'jsdom',
        globals: false,
        include: ['resources/js/**/*.spec.ts'],
        clearMocks: true,
        restoreMocks: true,
    },
});
