<script setup lang="ts">
/**
 * Placeholder — shared pre-step scaffold only (ADR 0013 §8).
 *
 * Wired into the router now so the route/type/nav layer builds and typechecks green before
 * the invoice lane starts. The invoice lane replaces this file's body with the real list
 * screen (§4.6) — search/filter/paginate over `listSalesInvoices`, per ADR 0013 §1.
 */
defineOptions({ name: 'SalesInvoicesListPage' })
</script>

<template>
  <div class="mx-auto max-w-6xl space-y-5">
    <header>
      <h1 class="text-2xl font-semibold text-content">Invoices</h1>
      <p class="mt-1 text-sm text-content-muted">Coming soon.</p>
    </header>
  </div>
</template>
