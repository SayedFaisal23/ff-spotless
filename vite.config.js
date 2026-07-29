import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import { VitePWA } from 'vite-plugin-pwa';

const hmrHost = process.env.VITE_HMR_HOST;
const hmrPort = Number(process.env.VITE_HMR_PORT || 5173);

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        vue(),
        tailwindcss(),
        VitePWA({
            strategies: 'injectManifest',
            srcDir: 'resources/js',
            filename: 'service-worker.js',
            injectRegister: null,
            manifest: false,
            includeAssets: [
                'favicon.ico',
                'icons/ff-spotless-icon.svg',
                'icons/ff-spotless-maskable.svg',
            ],
            injectManifest: {
                globPatterns: ['**/*.{js,css,svg,png,ico,woff2}'],
            },
        }),
    ],
    server: {
        host: process.env.VITE_HOST,
        port: hmrPort,
        strictPort: true,
        hmr: hmrHost ? { host: hmrHost, clientPort: hmrPort } : undefined,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
