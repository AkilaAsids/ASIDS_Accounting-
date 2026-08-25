<script setup lang="ts">
import { ref } from 'vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import OverflowMenu from '@/components/ui/OverflowMenu.vue'
import type { Customer } from '@/types/domain'

/**
 * Archive / restore / deactivate / reactivate / delete for one customer (ADR 0013 §1).
 *
 * Confirmation lives here rather than in the pages that mount this — both the list row and the
 * detail header need it, so this is the one place that owns the Tier-1 `window.confirm` copy
 * and the Tier-3 typed-confirm dialog for hard delete (PHASE-3-FRONTEND-DESIGN.md §1.2). The
 * actual request and its error handling stay with whichever page is listening: this component
 * never calls `api` itself, only emits an action once the reader has confirmed it.
 *
 * Every control is gated on `customer.capabilities`, never on `status` alone (requirements §3,
 * §4.5.6): an owner's raw permission is unconditional (`Gate::before`), so only the resource's
 * own capability flags may decide what is offered. `can_update` gates the whole reversible
 * action set (archive/restore/deactivate/reactivate); which *verb* is shown is read from
 * `status` for display selection only, never for whether the reader is allowed (ADR 0013 §4).
 *
 * "Delete" is never a peer button beside these — it is always tucked behind the overflow menu
 * (Gate-1 decision #2), and the trigger itself is not rendered at all when `can_delete` is
 * false, since an overflow menu offering nothing enabled is worse than no menu.
 */
const props = defineProps<{ customer: Customer }>()

const emit = defineEmits<{
  archive: []
  restore: []
  deactivate: []
  reactivate: []
  delete: []
}>()

const deleteDialogOpen = ref(false)

function onArchiveClick(): void {
  if (
    window.confirm(`Archive ${props.customer.code} ${props.customer.name}? Its history stays readable.`)
  ) {
    emit('archive')
  }
}

function onRestoreClick(): void {
  if (window.confirm(`Restore ${props.customer.code} ${props.customer.name}?`)) {
    emit('restore')
  }
}

function onDeactivateClick(): void {
  if (
    window.confirm(
      `Deactivate ${props.customer.code} ${props.customer.name}? It will no longer accept new invoices.`,
    )
  ) {
    emit('deactivate')
  }
}

function onReactivateClick(): void {
  if (window.confirm(`Reactivate ${props.customer.code} ${props.customer.name}?`)) {
    emit('reactivate')
  }
}

function onDeleteConfirmed(): void {
  deleteDialogOpen.value = false
  emit('delete')
}

function onDeleteMenuItemClick(close: () => void): void {
  deleteDialogOpen.value = true
  close()
}
</script>

<template>
  <div class="flex items-center justify-end gap-1">
    <BaseButton
      v-if="customer.capabilities.can_update && customer.status === 'active'"
      variant="ghost"
      size="sm"
      @click="onArchiveClick"
    >
      Archive
    </BaseButton>

    <BaseButton
      v-if="customer.capabilities.can_update && customer.status === 'archived'"
      variant="ghost"
      size="sm"
      @click="onRestoreClick"
    >
      Restore
    </BaseButton>

    <BaseButton
      v-if="customer.capabilities.can_update && customer.status === 'active'"
      variant="ghost"
      size="sm"
      @click="onDeactivateClick"
    >
      Deactivate
    </BaseButton>

    <BaseButton
      v-if="customer.capabilities.can_update && customer.status === 'inactive'"
      variant="ghost"
      size="sm"
      @click="onReactivateClick"
    >
      Reactivate
    </BaseButton>

    <OverflowMenu v-if="customer.capabilities.can_delete" :label="`More actions for ${customer.name}`">
      <template #default="{ close }">
        <button
          type="button"
          role="menuitem"
          class="block w-full px-3 py-1.5 text-left text-sm text-danger hover:bg-surface-sunken"
          @click="onDeleteMenuItemClick(close)"
        >
          Delete
        </button>
      </template>
    </OverflowMenu>

    <ConfirmDialog
      :open="deleteDialogOpen"
      mode="typed"
      title="Delete this customer?"
      danger
      confirm-label="Delete customer"
      :confirm-token="customer.code"
      @confirm="onDeleteConfirmed"
      @cancel="deleteDialogOpen = false"
    >
      This permanently removes the customer. Archiving is reversible and keeps the record; this
      does not.
    </ConfirmDialog>
  </div>
</template>
