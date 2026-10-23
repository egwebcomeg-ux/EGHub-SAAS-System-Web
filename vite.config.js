import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/menu.js', 'resources/js/board.js', 'resources/js/menu-editor.js', 'resources/js/pos.js', 'resources/js/kitchen.js', 'resources/js/zones-map.js', 'resources/css/landing.css', 'resources/js/landing.js', 'resources/js/kiosk.js', 'resources/js/notifications.js', 'resources/js/dash-ui.js', 'resources/js/voucher-redeem.js', 'resources/js/driver.js', 'resources/js/drivers.js', 'resources/js/dash-home.js', 'resources/js/qr-tool.js', 'resources/js/pager.js', 'resources/js/pager-station.js'],
            refresh: true,
            fonts: [
                // Arabic + Latin glyphs: without the arabic subset, Arabic text silently fell back to the OS font.
                // Only regular and bold are preloaded (Arabic + Latin files of each, ~57 KB instead of all 12 files);
                // the other weights still load through @font-face when a page uses them.
                bunny('Cairo', { weights: [400, 500, 600, 700, 800, 900], subsets: ['arabic', 'latin'], preload: [{ weight: 400 }, { weight: 700 }] }),
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
