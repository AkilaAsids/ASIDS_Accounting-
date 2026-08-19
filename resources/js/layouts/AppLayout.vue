<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink, RouterView, useRouter } from 'vue-router'
import CompanySwitcher from '@/components/app/CompanySwitcher.vue'
import ThemeToggle from '@/components/app/ThemeToggle.vue'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'

const auth = useAuthStore()
const ui = useUiStore()
const router = useRouter()
const userMenuOpen = ref(false)

interface NavItem {
  name: string
  label: string
  icon: string
  permission?: string
}

/**
 * Navigation is filtered by permission, so a bookkeeper does not see a Roles link that would
 * 403. Filtering here rather than in the template keeps the "which sections exist" question
 * answerable in one place as later phases add modules.
 */
const navigation = computed<NavItem[]>(() =>
  (
    [
      {
        name: 'dashboard',
        label: 'Dashboard',
        icon: 'M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z',
      },
      {
        name: 'users',
        label: 'Users',
        icon: 'M17 20h5v-2a3 3 0 00-5.36-1.86M17 20H7m10 0v-2c0-.66-.13-1.3-.36-1.86m0 0a5 5 0 00-9.28 0M7 20H2v-2a3 3 0 015.36-1.86M7 20v-2c0-.66.13-1.3.36-1.86m0 0a5 5 0 019.28 0M15 7a3 3 0 11-6 0 3 3 0 016 0z',
        permission: 'identity.users.view',
      },
      {
        name: 'chart-of-accounts',
        label: 'Accounts',
        icon: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
        permission: 'accounting.accounts.view',
      },
      {
        name: 'journal-entries',
        label: 'Journals',
        icon: 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253',
        permission: 'accounting.journals.view',
      },
      {
        name: 'trial-balance',
        label: 'Trial balance',
        icon: 'M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2',
        permission: 'accounting.reports.view',
      },
      {
        name: 'outstanding-receivables',
        label: 'Receivables',
        icon: 'M9 7h6m-6 4h6m-6 4h4M5 4a1 1 0 011-1h12a1 1 0 011 1v16l-3.5-2-3.5 2-3.5-2L5 20V4z',
        permission: 'sales.reports.view',
      },
      {
        name: 'aged-receivables',
        label: 'Aged receivables',
        icon: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
        permission: 'sales.reports.view',
      },
      {
        name: 'ar-control',
        label: 'AR control',
        icon: 'M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3',
        permission: 'sales.reports.view',
      },
      {
        name: 'roles',
        label: 'Roles',
        icon: 'M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z',
        permission: 'authorization.roles.view',
      },
      {
        name: 'settings',
        label: 'Settings',
        icon: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z',
      },
    ] satisfies NavItem[]
  ).filter((item) => item.permission === undefined || auth.can(item.permission)),
)

async function signOut(): Promise<void> {
  userMenuOpen.value = false
  await auth.signOut()
  await router.push({ name: 'sign-in' })
}
</script>

<template>
  <div class="flex min-h-screen bg-surface-sunken">
    <!-- ── Sidebar ─────────────────────────────────────────────────────── -->
    <aside
      class="hidden shrink-0 border-r border-surface-border bg-surface-raised transition-all lg:flex lg:flex-col"
      :class="ui.sidebarCollapsed ? 'lg:w-16' : 'lg:w-60'"
    >
      <div class="flex h-14 items-center gap-2 px-4">
        <span
          class="grid h-8 w-8 shrink-0 place-items-center rounded-md bg-primary-600 text-sm font-bold text-white"
        >
          A
        </span>
        <span v-if="!ui.sidebarCollapsed" class="truncate text-sm font-semibold text-content">
          {{ auth.workspace?.name ?? 'ASIDS' }}
        </span>
      </div>

      <nav class="flex-1 space-y-1 p-2" aria-label="Main">
        <RouterLink
          v-for="item in navigation"
          :key="item.name"
          :to="{ name: item.name }"
          class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium text-content-muted transition hover:bg-surface-sunken hover:text-content"
          active-class="bg-primary-600/10 text-primary-700 dark:text-primary-300"
          :title="ui.sidebarCollapsed ? item.label : undefined"
        >
          <svg
            class="h-5 w-5 shrink-0"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="1.8"
            aria-hidden="true"
          >
            <path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" />
          </svg>
          <span v-if="!ui.sidebarCollapsed">{{ item.label }}</span>
          <span v-else class="sr-only">{{ item.label }}</span>
        </RouterLink>
      </nav>

      <div class="border-t border-surface-border p-2">
        <button
          type="button"
          class="flex w-full items-center gap-3 rounded-md px-3 py-2 text-sm text-content-muted hover:bg-surface-sunken hover:text-content"
          :aria-expanded="!ui.sidebarCollapsed"
          @click="ui.toggleSidebar()"
        >
          <svg
            class="h-5 w-5 shrink-0"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="1.8"
            aria-hidden="true"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              :d="
                ui.sidebarCollapsed ? 'M13 5l7 7-7 7M5 5l7 7-7 7' : 'M11 19l-7-7 7-7m8 14l-7-7 7-7'
              "
            />
          </svg>
          <span v-if="!ui.sidebarCollapsed">Collapse</span>
          <span v-else class="sr-only">Expand sidebar</span>
        </button>
      </div>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
      <!-- ── Top bar ───────────────────────────────────────────────────── -->
      <header
        class="flex h-14 shrink-0 items-center gap-3 border-b border-surface-border bg-surface-raised px-4"
      >
        <CompanySwitcher />

        <div class="flex-1" />

        <!-- Surfaced in the chrome, not buried in billing: a trial that lapses without
             warning is a customer who loses access mid-invoice. -->
        <span
          v-if="auth.workspace?.on_trial"
          class="hidden rounded-full bg-warning/10 px-2.5 py-1 text-xs font-medium text-warning sm:inline"
        >
          Trial ends {{ new Date(auth.workspace.trial_ends_at ?? '').toLocaleDateString() }}
        </span>

        <ThemeToggle />

        <div class="relative">
          <button
            type="button"
            class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-surface-sunken"
            :aria-expanded="userMenuOpen"
            aria-haspopup="menu"
            @click="userMenuOpen = !userMenuOpen"
          >
            <span
              class="grid h-7 w-7 place-items-center rounded-full bg-primary-600 text-xs font-semibold text-white"
            >
              {{ auth.user?.initials }}
            </span>
            <span class="hidden max-w-32 truncate text-content sm:inline">{{
              auth.user?.full_name
            }}</span>
          </button>

          <div
            v-if="userMenuOpen"
            class="absolute right-0 z-20 mt-1 w-56 rounded-md border border-surface-border bg-surface-raised py-1 shadow-overlay animate-fade-in"
            role="menu"
          >
            <div class="border-b border-surface-border px-3 py-2">
              <p class="truncate text-sm font-medium text-content">{{ auth.user?.full_name }}</p>
              <p class="truncate text-xs text-content-muted">{{ auth.user?.email }}</p>
            </div>

            <RouterLink
              :to="{ name: 'security' }"
              class="flex items-center justify-between px-3 py-2 text-sm text-content-muted hover:bg-surface-sunken hover:text-content"
              role="menuitem"
              @click="userMenuOpen = false"
            >
              Security
              <!-- A nudge rather than a nag: 2FA is the single highest-value thing a user can
                   turn on, so its absence is worth showing every time this menu opens. -->
              <span
                v-if="!auth.user?.two_factor_enabled"
                class="rounded bg-warning/15 px-1.5 py-0.5 text-xs text-warning"
              >
                2FA off
              </span>
            </RouterLink>

            <button
              type="button"
              class="w-full px-3 py-2 text-left text-sm text-content-muted hover:bg-surface-sunken hover:text-content"
              role="menuitem"
              @click="signOut"
            >
              Sign out
            </button>
          </div>
        </div>
      </header>

      <main id="main" class="min-w-0 flex-1 p-4 sm:p-6">
        <RouterView />
      </main>
    </div>
  </div>
</template>
