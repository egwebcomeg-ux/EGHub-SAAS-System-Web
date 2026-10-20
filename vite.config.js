import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/menu.js', 'resources/js/board.js', 'resources/js/menu-editor.js', 'resources/js/pos.js', 'resources/js/kitchen.js', 'resources/js/zones-map.js', 'resources/css/landing.css', 'resources/js/landing.js', 'resources/js/kiosk.js', 'resources/js/notifications.js', 'resources/js/dash-ui.js', 'resources/js/voucher-redeem.js', 'resources/js/driver.js', 'resources/js/drivers.js'],
            refresh: true,
            fonts: [
                // Arabic + Latin glyphs: without the arabic subset, Arabic text silently fell back to the OS font.
                bunny('Cairo', { weights: [400, 500, 600, 700, 800, 900], subsets: ['arabic', 'latin'] }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
