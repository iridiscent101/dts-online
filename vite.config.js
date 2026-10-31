import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/css/dts.css', 'resources/js/dts.js', 'resources/js/floating-tooltip.js', 'resources/js/copy-button.js', 'resources/js/tracking-refresh.js', 'resources/js/timeline-reveal.js', 'resources/js/signature-pad.js', 'resources/js/client-updates.js'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        port: 5241,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
