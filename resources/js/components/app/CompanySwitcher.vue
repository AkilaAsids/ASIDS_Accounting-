<script setup lang="ts">
import { ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'

/**
 * Switches which company's books the session is working in.
 *
 * Hidden entirely for a single-company workspace — most SMEs have one, and a switcher with
 * one option is noise. Switching reloads the session rather than only updating a header,
 * because the company determines the base currency and fiscal calendar that every figure on
 * screen is formatted against.
 */
const auth = useAuthStore()
const ui = useUiStore()
const open = ref(false)
const switching = ref(false)

async function select(companyId: string): Promise<void> {
  if (companyId === auth.activeCompany?.id) {
    open.value = false
    return
  }

  switching.value = true

  try {
    await auth.selectCompany(companyId)
    open.value = false
  } catch {
    ui.notify('error', 'Could not switch company. Please try again.')
  } finally {
    switching.value = false
  }
}
</script>

<template>
  <div v-if="auth.companies.length > 1" class="relative">
    <button
      type="button"
      class="flex items-center gap-2 rounded-md border border-surface-border px-3 py-1.5 text-sm hover:bg-surface-sunken"
      :aria-expanded="open"
      aria-haspopup="listbox"
      :disabled="switching"
      @click="open = !open"
    >
      <span class="max-w-40 truncate font-medium text-content">{{ auth.activeCompany?.name }}</span>
      <span class="rounded bg-surface-sunken px-1.5 py-0.5 font-mono text-xs text-content-muted">
        {{ auth.activeCompany?.code }}
      </span>
      <svg class="h-4 w-4 text-content-subtle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
      </svg>
    </button>

    <ul
      v-if="open"
      class="absolute left-0 z-20 mt-1 w-72 rounded-md border border-surface-border bg-surface-raised py-1 shadow-overlay animate-fade-in"
      role="listbox"
    >
      <li v-for="company in auth.companies" :key="company.id">
        <button
          type="button"
          role="option"
          :aria-selected="company.id === auth.activeCompany?.id"
          class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-surface-sunken"
          @click="select(company.id)"
        >
          <span class="min-w-0">
            <span class="block truncate text-content">{{ company.name }}</span>
            <span class="block text-xs text-content-muted">
              {{ company.code }} · {{ company.base_currency_code }}
            </span>
          </span>
          <svg
            v-if="company.id === auth.activeCompany?.id"
            class="h-4 w-4 shrink-0 text-primary-600"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            aria-hidden="true"
          >
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
          </svg>
        </button>
      </li>
    </ul>
  </div>

  <!-- Single-company workspace: show the name, offer no choice. -->
  <span v-else-if="auth.activeCompany" class="text-sm font-medium text-content">
    {{ auth.activeCompany.name }}
  </span>
</template>
