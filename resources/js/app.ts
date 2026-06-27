import '../css/app.css';
import './bootstrap';
import '@fontsource-variable/geist';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, DefineComponent, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { router } from '@inertiajs/vue3';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Merge-on-login: check if user has localStorage favorites to merge
const STORAGE_KEY = 'ipop360_favorites';
let hasCheckedMerge = false;

function checkAndMergeFavorites(pageProps: any) {
    if (hasCheckedMerge) return;

    try {
        const stored = localStorage.getItem(STORAGE_KEY);
        if (!stored) return;

        const localFavorites = JSON.parse(stored);
        if (!Array.isArray(localFavorites) || localFavorites.length === 0) return;

        // Check if user is now authenticated
        const isAuthed = !!pageProps?.auth?.user;

        if (isAuthed) {
            // Prepare data for merge
            const ids: number[] = [];
            const venues: any[] = [];

            for (const fav of localFavorites) {
                if (fav.id && fav.id > 0) {
                    ids.push(fav.id);
                } else {
                    venues.push(fav.venue);
                }
            }

            // Mark as checked before the async call to prevent duplicate calls
            hasCheckedMerge = true;

            // Merge with server
            router.post(
                '/favorites/merge',
                { ids, venues },
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        // Clear localStorage on successful merge
                        localStorage.removeItem(STORAGE_KEY);
                    },
                    onError: () => {
                        // Reset on error so we can retry
                        hasCheckedMerge = false;
                        console.error('Failed to merge favorites');
                    },
                }
            );
        }
    } catch (e) {
        console.error('Failed to check/merge favorites', e);
    }
}

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        // Check for merge-on-login on initial page load
        checkAndMergeFavorites(props.initialPage.props);

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#f59e0b',
        includeCSS: true,
        showSpinner: false,
    },
});

// Also check on each navigation (for login redirect scenarios)
router.on('success', (event) => {
    checkAndMergeFavorites(event.detail.page.props);
});
