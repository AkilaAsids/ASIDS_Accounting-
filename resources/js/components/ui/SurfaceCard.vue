<script setup lang="ts">
defineProps<{ title?: string; description?: string }>()
</script>

<template>
  <section class="rounded-card border border-surface-border bg-surface-raised shadow-card">
    <header v-if="title" class="border-b border-surface-border px-5 py-4">
      <h2 class="text-base font-semibold text-content">{{ title }}</h2>
      <p v-if="description" class="mt-0.5 text-sm text-content-muted">{{ description }}</p>
      <slot name="actions" />
    </header>
    <div class="px-5 py-4"><slot /></div>
    <footer v-if="$slots.footer" class="border-t border-surface-border bg-surface-sunken px-5 py-3">
      <slot name="footer" />
    </footer>
  </section>
</template>
