import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/lottery.css',
                'resources/js/app.js',
                'resources/js/lottery/ticket-selector.js',
                'resources/js/lottery/countdown.js',
                'resources/js/lottery/bet-slip.js',
                'resources/js/lottery/live-results.js',
                'resources/js/wallet/wallet-balance.js',
            ],
            refresh: true,
        }),
    ],
    server: {
        host: '0.0.0.0',
        port: 5173,
    },
});
