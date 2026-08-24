<script setup lang="ts">
/**
 * Placeholder — shared pre-step scaffold only (ADR 0013 §8).
 *
 * Serves both `customer-new` and `customer-edit` (the router distinguishes them by whether
 * `route.params.customerId` is present). Wired into the router now so the route/type/nav
 * layer builds and typechecks green before the customer lane starts; the customer lane
 * replaces this file's body with the real create/edit form (§4.2/§4.3), including the
 * clear-vs-omit payload discipline against `createCustomer`/`updateCustomer`.
 */
defineOptions({ name: 'CustomerFormPage' })
</script>

<template>
  <div class="mx-auto max-w-5xl space-y-5">
    <header>
      <h1 class="text-2xl font-semibold text-content">Customer</h1>
      <p class="mt-1 text-sm text-content-muted">Coming soon.</p>
    </header>
  </div>
</template>
