import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import statamic from '@statamic/cms/vite-plugin';

export default defineConfig({
    plugins: [
        // Externalises `vue` to the Control Panel runtime. Without it the bundle
        // ships a second Vue, and provide/inject returns null across the seam.
        statamic(),
        laravel({
            input: ['resources/js/cp.js'],
            // Must byte-match $vite in ServiceProvider.
            publicDirectory: 'dist',
            hotFile: 'dist/hot',
        }),
    ],
});
