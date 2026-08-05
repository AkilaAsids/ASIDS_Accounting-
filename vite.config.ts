import { fileURLToPath, URL } from 'node:url'
import vue from '@vitejs/plugin-vue'
import laravel from 'laravel-vite-plugin'
// `vitest/config` re-exports Vite's `defineConfig` with the `test` block typed,
// so a single config file drives both the dev server and the unit test runner.
import { defineConfig } from 'vitest/config'

export default defineConfig({
  plugins: [
    laravel({
      input: ['resources/js/app/main.ts', 'resources/js/styles/app.css'],
      refresh: ['resources/views/**', 'routes/**'],
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

  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
    },
  },

  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    hmr: {
      host: 'localhost',
    },
  },

  build: {
    sourcemap: true,
    // Keep the initial payload small: the accounting, reporting and admin
    // areas are lazily routed, so they must not land in the entry chunk.
    rollupOptions: {
      output: {
        manualChunks: {
          vendor: ['vue', 'vue-router', 'pinia'],
          charts: ['chart.js'],
        },
      },
    },
  },

  test: {
    environment: 'happy-dom',
    globals: true,
    include: ['resources/js/**/*.spec.ts'],
    setupFiles: ['tests/Support/vitest.setup.ts'],
    coverage: {
      provider: 'v8',
      reporter: ['text', 'html'],
      include: ['resources/js/**/*.{ts,vue}'],
      exclude: ['resources/js/**/*.spec.ts', 'resources/js/types/**'],
      thresholds: {
        statements: 80,
        branches: 75,
        functions: 80,
        lines: 80,
      },
    },
  },
})
