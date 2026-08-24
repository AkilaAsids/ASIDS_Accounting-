<script setup lang="ts">
/**
 * Placeholder — shared pre-step scaffold only (ADR 0013 §8).
 *
 * Serves both `invoice-new` and `invoice-edit` (the router distinguishes them by whether
 * `route.params.invoiceId` is present). Wired into the router now so the route/type/nav
 * layer builds and typechecks green before the invoice lane starts; the invoice lane
 * replaces this file's body with the real draft create/edit screen (§4.7/§4.8) and the line
 * editor (§7) — no client money arithmetic, totals rendered only from the last API response.
 */
defineOptions({ name: 'SalesInvoiceEditorPage' })
</script>

<template>
  <div class="mx-auto max-w-5xl space-y-5">
    <header>
      <h1 class="text-2xl font-semibold text-content">Invoice</h1>
      <p class="mt-1 text-sm text-content-muted">Coming soon.</p>
    </header>
  </div>
</template>
