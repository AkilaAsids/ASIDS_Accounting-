<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import AlertBanner from '@/components/ui/AlertBanner.vue'
import SurfaceCard from '@/components/ui/SurfaceCard.vue'
import { useAuthStore } from '@/stores/auth'
import { useFormat } from '@/composables/useFormat'

/**
 * Phase 1 dashboard.
 *
 * There is no financial data to show yet — no ledger exists — so rather than inventing
 * placeholder charts this page does the two useful things it honestly can: confirm the
 * workspace is set up, and surface security work the user actually needs to do.
 * Fabricated widgets would have to be found and removed later, and would set a false
 * expectation of what is built.
 */
const auth = useAuthStore()
const { date } = useFormat()

const outstanding = computed(() => {
  const items: Array<{ label: string; to: string; kind: 'warning' | 'info' }> = []

  if (!auth.user?.two_factor_enabled) {
    items.push({
      label: 'Turn on two factor authentication',
      to: 'security',
      kind: 'warning',
    })
  }

  if (auth.user?.email_verified === false) {
    items.push({ label: 'Confirm your e-mail address', to: 'security', kind: 'info' })
  }

  return items
})
</script>

<template>
  <div class="mx-auto max-w-5xl space-y-6">
    <header>
      <h1 class="text-2xl font-semibold text-content">Good day, {{ auth.user?.first_name }}</h1>
      <p class="mt-1 text-sm text-content-muted">
        {{ auth.activeCompany?.name }}
        <span v-if="auth.activeCompany" class="text-content-subtle">
          · {{ auth.activeCompany.base_currency_code }}
        </span>
      </p>
    </header>

    <AlertBanner v-if="auth.workspace?.on_trial" kind="info" title="You are on a trial">
      Your trial ends on {{ date(auth.workspace.trial_ends_at) }}. Everything you enter now is kept
      when you subscribe.
    </AlertBanner>

    <SurfaceCard
      v-if="outstanding.length > 0"
      title="Worth doing"
      description="A few things to finish setting up."
    >
      <ul class="divide-y divide-surface-border">
        <li
          v-for="item in outstanding"
          :key="item.label"
          class="flex items-center justify-between py-3 first:pt-0 last:pb-0"
        >
          <span class="flex items-center gap-2 text-sm text-content">
            <span
              class="h-1.5 w-1.5 shrink-0 rounded-full"
              :class="item.kind === 'warning' ? 'bg-warning' : 'bg-info'"
              aria-hidden="true"
            />
            {{ item.label }}
          </span>
          <RouterLink
            :to="{ name: item.to }"
            class="text-sm text-primary-700 hover:underline dark:text-primary-400"
          >
            Go
          </RouterLink>
        </li>
      </ul>
    </SurfaceCard>

    <SurfaceCard title="Your workspace">
      <dl class="grid gap-4 sm:grid-cols-2">
        <div>
          <dt class="text-xs uppercase tracking-wide text-content-subtle">Workspace</dt>
          <dd class="mt-0.5 text-sm text-content">{{ auth.workspace?.name }}</dd>
        </div>
        <div>
          <dt class="text-xs uppercase tracking-wide text-content-subtle">Companies</dt>
          <dd class="mt-0.5 text-sm text-content">{{ auth.companies.length }}</dd>
        </div>
        <div>
          <dt class="text-xs uppercase tracking-wide text-content-subtle">Your role</dt>
          <dd class="mt-0.5 text-sm text-content">
            {{ auth.user?.roles?.map((role) => role.label).join(', ') || 'No role assigned' }}
          </dd>
        </div>
        <div>
          <dt class="text-xs uppercase tracking-wide text-content-subtle">Fiscal year</dt>
          <dd class="mt-0.5 text-sm text-content tabular">Starts 1 April</dd>
        </div>
      </dl>
    </SurfaceCard>

    <p class="text-center text-xs text-content-subtle">
      Accounting, sales, purchasing and reporting arrive in the next phases. What you can set up
      today is your workspace, your companies and your people.
    </p>
  </div>
</template>
