<script setup lang="ts">
import BaseButton from '@/components/ui/BaseButton.vue'
import type { Pagination } from '@/types/api'

/**
 * The first pagination control in the codebase.
 *
 * Generalises the inline prev/next footer `UsersPage.vue` already hand-rolls, so both the
 * customer and invoice lists render an identical control rather than two that quietly drift
 * apart on page-size or wording (ADR 0011/§8's named risk). `per_page` is not a prop here —
 * the server's default is accepted everywhere, so there is nothing for the two lists to
 * disagree about.
 *
 * Numbered pages are explicitly out of scope for this wave (Gate-1): prev/next matches the
 * one existing precedent and is the least error-prone control for a keyboard/screen-reader
 * user on a dense financial list. Adding numbers later only touches this file.
 */
withDefaults(defineProps<{ pagination: Pagination; disabled?: boolean }>(), { disabled: false })

const emit = defineEmits<{ 'update:page': [page: number] }>()
</script>

<template>
  <!--
    Self-guards against a consumer forgetting the `last_page > 1` check: a single-page result
    renders nothing rather than a permanently-disabled, useless pair of buttons.
  -->
  <nav
    v-if="pagination.last_page > 1"
    aria-label="Pagination"
    class="flex items-center justify-between text-sm"
  >
    <p class="text-content-muted" aria-live="polite">
      <!--
        `from`/`to` are nullable on an empty page. Rendered as 0 rather than left to print
        "null–null", which is the `Pagination` type's own documented edge case.
      -->
      {{ pagination.from ?? 0 }}–{{ pagination.to ?? 0 }} of {{ pagination.total }}
    </p>

    <div class="flex gap-2">
      <BaseButton
        variant="secondary"
        size="sm"
        :disabled="disabled || pagination.current_page <= 1"
        @click="emit('update:page', pagination.current_page - 1)"
      >
        Previous
      </BaseButton>
      <BaseButton
        variant="secondary"
        size="sm"
        :disabled="disabled || pagination.current_page >= pagination.last_page"
        @click="emit('update:page', pagination.current_page + 1)"
      >
        Next
      </BaseButton>
    </div>
  </nav>
</template>
