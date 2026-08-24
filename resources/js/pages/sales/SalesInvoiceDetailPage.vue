<script setup lang="ts">
/**
 * Placeholder — shared pre-step scaffold only (ADR 0013 §8).
 *
 * Wired into the router now so the route/type/nav layer builds and typechecks green before
 * the invoice lane starts. The invoice lane replaces this file's body with the real view
 * screen (§4.9–4.12) — `getSalesInvoice` plus the capabilities-gated issue/cancel/delete
 * actions.
 */
defineOptions({ name: 'SalesInvoiceDetailPage' })
</script>

<template>
  <div class="mx-auto max-w-5xl space-y-5">
    <header>
      <h1 class="text-2xl font-semibold text-content">Invoice</h1>
      <p class="mt-1 text-sm text-content-muted">Coming soon.</p>
    </header>
  </div>
</template>
