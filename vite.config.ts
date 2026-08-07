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

      /*
       * Thresholds are per layer, not one global average, and that is deliberate.
       *
       * A single global number lets a large well-covered layer carry a poorly covered one. With
       * thirteen page components in this codebase — several of them three hundred lines of template —
       * a global figure is dominated by markup, and a real regression in `api/client.ts` would hide
       * inside it. Per-glob gates mean each layer answers for itself.
       *
       * The page and bootstrap layers sit at zero, stated openly rather than excluded from the report
       * so the gap stays visible in every run. The reasoning: those files are overwhelmingly
       * declarative, and the behaviour they wire together is already covered from both ends — by the
       * API client, store, router-guard and component tests below them, and by the Pest suite's
       * end-to-end coverage of every endpoint they call. Mounting each page to assert that a table
       * renders a row tests Vue, not this application. Raising this floor is worthwhile work, and it
       * is worth doing when a page starts carrying logic of its own rather than for the number.
       */
      thresholds: {
        'resources/js/{api,stores,composables,router}/**': {
          statements: 90,
          branches: 85,
          // `router/index.ts` is a route table: most of its functions are lazy-import arrows that
          // only run when a page is actually navigated to, and the guard tests stub the pages.
          functions: 75,
          lines: 90,
        },
        'resources/js/components/**': {
          statements: 95,
          branches: 90,
          functions: 95,
          lines: 95,
        },
        'resources/js/{pages,app}/**': {
          statements: 0,
          branches: 0,
          functions: 0,
          lines: 0,
        },
      },
    },
  },
})
