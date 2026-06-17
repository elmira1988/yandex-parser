import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: 'resources/js/app.js',
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    // 🌟 ВОЗВРАЩАЕМ СЕТЕВЫЕ НАСТРОЙКИ ДЛЯ DOCKER:
    server: {
        host: '0.0.0.0',
        port: 3000,
        strictPort: true,
        cors: true,
        hmr: {
            host: 'local.yandex-parser.ru',
        },
    },
});
