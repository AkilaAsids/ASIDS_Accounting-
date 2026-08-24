<script setup lang="ts">
/**
 * Placeholder — shared pre-step scaffold only (ADR 0013 §8).
 *
 * Wired into the router now so the route/type/nav layer builds and typechecks green before
 * the customer lane starts. The customer lane replaces this file's body with the real view
 * screen (§4.4/§4.5) — `getCustomer` plus the capabilities-gated lifecycle actions.
 */
defineOptions({ name: 'CustomerDetailPage' })
</script>

<template>
  <div class="mx-auto max-w-5xl space-y-5">
    <header>
      <h1 class="text-2xl font-semibold text-content">Customer</h1>
      <p class="mt-1 text-sm text-content-muted">Coming soon.</p>
    </header>
  </div>
</template>
